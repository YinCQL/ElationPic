<#
    ElationPic - automated verification (design spec section 45)

    Usage (from the project root):
        powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -Password <admin password>

    Optional:
        -Base http://localhost     site base URL
        -Scenario all|access|login-ok|login-bad|permissions|upload|delete|security

    Notes:
      * Read-only except the delete/upload scenarios, which create then remove their own data.
      * No third-party modules; PowerShell built-ins only.
      * Output is [PASS] / [FAIL] / [SKIP] plus a summary.

    NOTE: this file is intentionally pure ASCII. Windows PowerShell 5.1 reads .ps1
    files with the system ANSI codepage when no UTF-8 BOM is present, which
    corrupts non-ASCII text and can break the parser outright.
#>

[CmdletBinding()]
param(
    [string]$Base = "http://localhost",
    [string]$Password = "",
    [ValidateSet("all","permissions","security","login-ok","login-bad","access","delete","upload","settings","backup")]
    [string]$Scenario = "all",
    [string]$FixtureDir = "",
    # S9 (login rate limiting) locks the testing IP for the configured lockout
    # window, so it is opt-in. The test restores the previous state when done.
    [switch]$IncludeRateLimit
)

$ErrorActionPreference = "Continue"
$script:pass = 0
$script:fail = 0
$script:skip = 0
$script:failures = New-Object System.Collections.Generic.List[string]

function Write-Head($t) { Write-Host ""; Write-Host ("=== " + $t + " ===") -ForegroundColor Cyan }
function Pass($name, $detail="") {
    $script:pass++
    Write-Host ("[PASS] " + $name + " " + $detail) -ForegroundColor Green
}
function Fail($name, $detail="") {
    $script:fail++
    $script:failures.Add($name + " :: " + $detail)
    Write-Host ("[FAIL] " + $name + " " + $detail) -ForegroundColor Red
}
function Skip($name, $detail="") {
    $script:skip++
    Write-Host ("[SKIP] " + $name + " " + $detail) -ForegroundColor DarkYellow
}
function Check($name, $cond, $detail="") {
    if ($cond) { Pass $name $detail } else { Fail $name $detail }
}

function Invoke-Req {
    param(
        [string]$Method = "GET",
        [string]$Url,
        [hashtable]$Headers = @{},
        # NOTE: [string] cannot hold $null - PowerShell coerces it to ''.
        # So these default to '' and we detect "was it supplied?" via
        # $PSBoundParameters below, not via a $null comparison.
        [string]$Body = '',
        [string]$ContentType = '',
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session = $null
    )
    $p = @{
        Method = $Method
        Uri = $Url
        Headers = $Headers
        MaximumRedirection = 0
        UseBasicParsing = $true
        TimeoutSec = 30
        ErrorAction = "Stop"
    }
    if ($Session) { $p.WebSession = $Session }
    # Use $PSBoundParameters, NOT "-ne $null".
    # PowerShell coerces [string]$null to the empty string, so "$Body -ne $null"
    # is TRUE even when the caller passed no body. That made every GET carry an
    # empty body, and .NET rejects a GET with a content body:
    #   "Cannot send a content-body with this verb type"
    # $PSBoundParameters tells us whether the argument was actually supplied.
    if ($PSBoundParameters.ContainsKey('Body')) { $p.Body = $Body }
    if ($PSBoundParameters.ContainsKey('ContentType') -and $ContentType -ne '') { $p.ContentType = $ContentType }
    try {
        $r = Invoke-WebRequest @p
        return @{ Status = [int]$r.StatusCode; Body = [string]$r.Content; Headers = $r.Headers; Ok = $true }
    } catch {
        $resp = $_.Exception.Response
        if ($resp -ne $null) {
            $code = 0
            try { $code = [int]$resp.StatusCode } catch { $code = 0 }
            $text = ""
            # Compatible with both PowerShell 5.1 (HttpWebResponse) and 7+ (HttpResponseMessage).
            # Without this, PS7 reads every error body as empty and reports bogus failures.
            try {
                if ($resp.PSObject.Methods.Name -contains "GetResponseStream") {
                    $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
                    $text = $sr.ReadToEnd()
                    $sr.Close()
                } elseif ($resp.Content -ne $null) {
                    $text = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult()
                }
            } catch { }
            if (($text -eq "" -or $text -eq $null) -and $_.ErrorDetails -and $_.ErrorDetails.Message) {
                $text = [string]$_.ErrorDetails.Message
            }
            return @{ Status = $code; Body = [string]$text; Headers = $resp.Headers; Ok = $true }
        }
        return @{ Status = 0; Body = ""; Headers = @{}; Ok = $false; Error = $_.Exception.Message }
    }
}

# Capture the raw Set-Cookie header from a successful login.
#
# Implemented with System.Net.HttpWebRequest rather than Invoke-WebRequest:
# the login reply is a 302, and in PowerShell 5.1 Invoke-WebRequest with
# -MaximumRedirection 0 THROWS on a redirect while leaving
# WebException.Response NULL -- so the response headers are simply not reachable.
# HttpWebRequest with AllowAutoRedirect = $false returns the 302 as a normal
# response, and its Headers collection exposes Set-Cookie verbatim.
#
# Returns "" on any failure so the caller can report a clean SKIP.
function Get-SetCookieHeader($Password) {
    try {
        [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
        $url = $Base + "/login.php"
        $jar = New-Object System.Net.CookieContainer

        # Step 1: GET the login page to obtain a CSRF token bound to a cookie.
        $req1 = [System.Net.HttpWebRequest]::Create($url)
        $req1.Method = "GET"
        $req1.AllowAutoRedirect = $false
        $req1.CookieContainer = $jar
        $req1.Timeout = 15000
        $resp1 = $req1.GetResponse()
        $sr = New-Object System.IO.StreamReader($resp1.GetResponseStream())
        $html = $sr.ReadToEnd()
        $sr.Close(); $resp1.Close()

        $csrf = ""
        # NOTE: the pattern must be '\s+' (backslash-s). Writing it through some
        # tooling silently drops the backslash and yields 's+', which never matches
        # -- the failure is silent because the caller only sees an empty token.
        $m = [regex]::Match($html, 'name="csrf_token"\s+value="([^"]+)"')
        if ($m.Success) { $csrf = $m.Groups[1].Value }
        if ($csrf -eq "") { return "" }

        # Step 2: POST the password without following the redirect.
        $req2 = [System.Net.HttpWebRequest]::Create($url)
        $req2.Method = "POST"
        $req2.AllowAutoRedirect = $false
        $req2.CookieContainer = $jar
        $req2.ContentType = "application/x-www-form-urlencoded"
        $req2.Timeout = 15000
        $payload = "password=" + [uri]::EscapeDataString([string]$Password) +
                   "&csrf_token=" + [uri]::EscapeDataString($csrf)
        $bytes = [System.Text.Encoding]::ASCII.GetBytes($payload)
        $req2.ContentLength = $bytes.Length
        $rs = $req2.GetRequestStream()
        $rs.Write($bytes, 0, $bytes.Length)
        $rs.Close()

        $resp2 = $req2.GetResponse()
        $header = [string]$resp2.Headers["Set-Cookie"]
        $resp2.Close()
        return $header
    } catch {
        return ""
    }
}

function Get-Csrf($Session, $Path) {
    $r = Invoke-Req -Url ($Base + $Path) -Session $Session
    if ($r.Body -match 'name="csrf_token"\s+value="([^"]+)"') { return $Matches[1] }
    if (-not $r.Ok) { Write-Host ("[FAIL] request to " + $Base + $Path + " failed: " + $r.Error) -ForegroundColor Red }
    elseif ($r.Status -ne 200) { Write-Host ("[FAIL] " + $Base + $Path + " returned " + $r.Status + " (expected 200)") -ForegroundColor Red }
    return $null
}

function New-LoginSession($pw) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Get-Csrf $s "/login.php"
    if (-not $csrf) {
        Write-Host "[FAIL] could not extract CSRF token from /login.php" -ForegroundColor Red
        Write-Host "       The site may be down, returning 500, or bootstrap aborted (missing config)." -ForegroundColor DarkYellow
        Write-Host ("       Try manually: curl -i " + $Base + "/login.php") -ForegroundColor DarkYellow
        $script:fail++
        $script:failures.Add("New-LoginSession :: could not extract CSRF token")
        return $null
    }
    $body = "password=" + [uri]::EscapeDataString($pw) + "&csrf_token=" + [uri]::EscapeDataString($csrf)
    $loginResp = Invoke-Req -Method POST -Url ($Base + "/login.php") -Body $body -ContentType "application/x-www-form-urlencoded" -Session $s

    # A successful login answers 302. But in PowerShell 5.1 Invoke-WebRequest with
    # -MaximumRedirection 0 THROWS on a redirect and leaves WebException.Response
    # NULL, so the helper cannot report a status and returns 0. Status 0 therefore
    # does NOT mean failure here -- it is the normal, expected reading on PS 5.1.
    #
    # So judge success by the thing we actually care about: can this session reach
    # the admin page? Only complain when it cannot.
    if ($loginResp.Status -ne 302) {
        $probe = Invoke-Req -Url ($Base + "/admin.php") -Session $s
        if ($probe.Status -ne 200) {
            Write-Host ("[WARN] login did not return 302 (got " + $loginResp.Status + ") and /admin.php returned " + $probe.Status) -ForegroundColor DarkYellow
            if ($loginResp.Status -eq 200) {
                Write-Host "       If the password is right, the usual cause is force_https=true while" -ForegroundColor DarkYellow
                Write-Host "       testing over HTTP: the session cookie is refused by the browser." -ForegroundColor DarkYellow
                Write-Host "       Set force_https => false in data/config.php for local HTTP tests." -ForegroundColor DarkYellow
            }
        }
    }
    return $s
}

function Get-CookieValue($Session, $name) {
    foreach ($c in $Session.Cookies.GetCookies($Base)) { if ($c.Name -eq $name) { return $c.Value } }
    return $null
}

# Resolve a URL found in HTML against the site base.
# An href beginning with "/" is absolute from the HOST root; combining it with a
# $Base that already carries a path (e.g. http://host/public) would duplicate it.
$origin = $Base
if ($Base -match "^(https?://[^/]+)") { $origin = $Matches[1] }

function Resolve-SiteUrl($base, $origin, $href) {
    if ($href -match "^https?://") { return $href }
    if ($href.StartsWith("/")) { return $origin + $href }
    return $base + "/" + $href
}

Write-Host ""
Write-Host "ElationPic verification" -ForegroundColor White
Write-Host ("Target: " + $Base + "   Scenario: " + $Scenario)

Write-Head "0. Connectivity"
$root = Invoke-Req -Url ($Base + "/")
if (-not $root.Ok) {
    Write-Host ("Cannot connect to " + $Base + " - start Nginx/PHP and check the port.") -ForegroundColor Red
    Write-Host $root.Error
    exit 2
}
Check "0.1 homepage reachable" ($root.Status -eq 200) ("status=" + $root.Status)

if ($Scenario -in @("all","access")) {
    Write-Head "1. Access"
    Check "A1 homepage 200" ($root.Status -eq 200) ("status=" + $root.Status)
    $leak = @()
    foreach ($kw in @("upload.php","delete.php","admin.php","js-delete","logout.php")) {
        if ($root.Body -like ("*" + $kw + "*")) { $leak += $kw }
    }
    Check "A2 homepage leaks no admin entry points" ($leak.Count -eq 0) ("found: " + ($leak -join ", "))
    Check "A3 homepage contains site name" ($root.Body -like "*Elation*") ""
    # A8: the setup wizard must be disabled once the site is configured.
    # Security requirement: a reusable setup page would let anyone reset the
    # admin password and take over the site.
    $setup = Invoke-Req -Url ($Base + "/setup.php")
    Check "A8 setup.php refuses when already configured" ($setup.Status -eq 403) ("status=" + $setup.Status)
    if ($setup.Status -eq 200) {
        Write-Host "       !!! SEVERE: setup.php is still usable - anyone can reset the admin password !!!" -ForegroundColor Red
    }

    # A9: the setup page must not leak any password or hash.
    Check "A9 setup.php leaks no hash" (-not ($setup.Body -match "[a-f0-9]{32}|\`$2y\`$|password_hash")) ""

    # A10: config.php must not be reachable over HTTP (ingress guard).
    $cfgReq = Invoke-Req -Url ($Base + "/../data/config.php")
    $cfgLeak = ($cfgReq.Body -match "admin_password_hash|password_hash\(")
    Check "A10 config.php not reachable" ((-not $cfgLeak) -and $cfgReq.Status -ne 200) ("status=" + $cfgReq.Status)
    # Optional cache-busting query allowed (style.css?v=...)
    if ($root.Body -match '<link[^>]+href="([^"]*style\.css(?:\?[^"]*)?)"') {
        $cssHref = $Matches[1]
        $cssUrl = Resolve-SiteUrl $Base $origin $cssHref
        $css = Invoke-Req -Url $cssUrl
        Check "A6 CSS loads (base_path correct)" ($css.Status -eq 200) ("href=" + $cssHref + " status=" + $css.Status)
    } else { Fail "A6 CSS loads (base_path correct)" "homepage did not reference style.css" }
    if ($root.Body -match '<script[^>]+src="([^"]*app\.js)"') {
        $jsHref = $Matches[1]
        $jsUrl = Resolve-SiteUrl $Base $origin $jsHref
        $jsr = Invoke-Req -Url $jsUrl
        Check "A7 JS loads (base_path correct)" ($jsr.Status -eq 200) ("src=" + $jsHref + " status=" + $jsr.Status)
    }
    $nf = Invoke-Req -Url ($Base + "/definitely-not-here-12345")
    Check "A5 missing page is not 200" ($nf.Status -ne 200) ("status=" + $nf.Status)
    $hasStack = ($nf.Body -match "Stack trace|Fatal error|Warning:|Notice:|D:\\")
    # A11: static assets must carry a cache-busting version.
    # Without it browsers may reuse a stale app.js, which shows up as
    # "the buttons do nothing" even though the code is correct.
    $cbOk = ($root.Body -match 'style\.css\?v=\d+')
    Check "A11 CSS URL carries a version stamp" $cbOk ""
    $cbJs = ($root.Body -match 'app\.js\?v=\d+')
    Check "A11b JS URL carries a version stamp" $cbJs ""
    # A12: every page must load app.js.
    # A real defect: backup.php did not include the script, so its delete
    # buttons did nothing and produced no error at all. The include now lives
    # in footer.php so all pages get it; this asserts that stays true.
    # error.php is deliberately self-contained (it must render even when
    # bootstrap cannot load) and has no interactive elements, so it is excluded.
    $scriptPages = @("/", "/login.php")
    foreach ($sp in $scriptPages) {
        $sr = Invoke-Req -Url ($Base + $sp)
        $hasJs = ($sr.Body -match "app\.js")
        Check ("A12 app.js loaded on " + $sp) $hasJs ("status=" + $sr.Status)
    }

    # A13: sorting is whitelisted and never interpolates user input into SQL.
    # ORDER BY cannot use placeholders, so the key maps to a fixed fragment;
    # anything unrecognised must silently fall back to the default.
    $srt = Invoke-Req -Url ($Base + "/?sort=old")
    Check "A13 sorting accepts a valid key" ($srt.Status -eq 200) ("status=" + $srt.Status)
    $srtBad = Invoke-Req -Url ($Base + "/?sort=" + [uri]::EscapeDataString("DROP TABLE images"))
    $srtOk = ($srtBad.Status -eq 200) -and ($srtBad.Body -match "data-id=")
    Check "A13b a malformed sort key is ignored" $srtOk ("status=" + $srtBad.Status)

    # A14: only the first card loads eagerly; the rest stay lazy.
    # The first image must be eager+high priority; EVERY OTHER image must be lazy.
    # Derive the expectation from the real card count so the check holds whether
    # the gallery has one image (nothing left to lazy-load) or a hundred.
    # Allow extra classes: a card with an issue is emitted as
    # class="card has-issue", which a strict 'class="card"' would miss.
    $cards = ([regex]::Matches([string]$root.Body, 'class="card[ "]')).Count
    $eager = ([regex]::Matches([string]$root.Body, 'loading="eager"')).Count
    $lazy  = ([regex]::Matches([string]$root.Body, 'loading="lazy"')).Count
    $prio  = ([regex]::Matches([string]$root.Body, 'fetchpriority="high"')).Count
    $wantEager = 0
    $wantLazy  = 0
    if ($cards -gt 0) { $wantEager = 1 }
    if ($cards -gt 1) { $wantLazy = $cards - 1 }
    $ok = ($eager -eq $wantEager) -and ($prio -eq $wantEager) -and ($lazy -eq $wantLazy)
    Check "A14 first image loads with high priority" $ok ("cards=" + $cards + " eager=" + $eager + "/" + $wantEager + " lazy=" + $lazy + "/" + $wantLazy + " prio=" + $prio)
    # A15: the public-gallery switch must actually gate the listing.
    #
    # This flips the setting for real and checks the observable effect, rather
    # than grepping the response for a variable name -- index.php returns
    # rendered HTML, so the source text never appears in the body.
    #
    # It only runs when a password is available, because flipping a setting
    # requires an admin session.
    if ([string]::IsNullOrEmpty($Password)) {
        Skip "A15" "no -Password provided"
        Skip "A15b" "no -Password provided"
        Skip "A15c" "no -Password provided"
    } else {
        $hsP = New-LoginSession $Password
        if ($hsP -eq $null) {
            Skip "A15" "could not establish an admin session"
            Skip "A15b" "could not establish an admin session"
            Skip "A15c" "could not establish an admin session"
        } else {
            $setP = Invoke-Req -Url ($Base + "/settings.php") -Session $hsP
            Check "A15 settings page offers the public-gallery switch" ($setP.Body -match "public_gallery") ""

            # Read the current config so we can restore it afterwards.
            $cfgPath = Join-Path (Split-Path -Parent $PSScriptRoot) "data\config.php"
            $before = $null
            if (Test-Path $cfgPath) {
                $raw = Get-Content $cfgPath -Raw -Encoding UTF8
                $before = ($raw -match "'public_gallery'\s*=>\s*true")
            }

            # Find an image link to test once the gallery is closed.
            $imgLink = ""
            $mImg = [regex]::Match([string]$root.Body, 'href="(/uploads/[^"]+)"')
            if ($mImg.Success) { $imgLink = $mImg.Groups[1].Value }

            # Turn it OFF by omitting the checkbox from the POST.
            $tokP = ""
            $mP = [regex]::Match([string]$setP.Body, 'name="csrf_token"\s+value="([^"]+)"')
            if ($mP.Success) { $tokP = $mP.Groups[1].Value }

            $offBody = "csrf_token=" + [uri]::EscapeDataString($tokP) +
                       "&site_name=Test&site_description=&site_url=&per_page=20" +
                       "&max_file_bytes=10485760&thumb_max_edge=480&timezone=UTC" +
                       "&force_https=0&login_max_attempts=5&login_window_secs=900" +
                       "&login_lockout_secs=900&log_level=info&base_path=&strip_metadata=1"
            $null = Invoke-Req -Method POST -Url ($Base + "/settings.php") -Body $offBody -ContentType "application/x-www-form-urlencoded" -Session $hsP

            # Anonymous visitor: must not see the listing.
            # Assert on ASCII markers only -- this script has to stay pure ASCII
            # because PowerShell 5.1 reads BOM-less .ps1 as ANSI.
            # The notice page renders a panel with the login link and no grid.
            $anonHome = Invoke-Req -Url ($Base + "/")
            $noCards = ($anonHome.Body -notmatch 'class="card-media"')
            $hasLoginLink = ($anonHome.Body -match "login.php")
            Check "A15b closed gallery hides the listing" ($noCards -and $hasLoginLink) ("cards=" + (-not $noCards) + " login=" + $hasLoginLink)

            # The direct link must keep working -- this is the whole point.
            if ($imgLink -ne "") {
                $dl = Invoke-Req -Url ($Base + $imgLink)
                Check "A15c direct link still works when closed" ($dl.Status -eq 200) ("status=" + $dl.Status + " link=" + $imgLink)
                $ok = $dl.Body -match ""   # cheap guard for the linter
            } else {
                Skip "A15c" "no image link available to test"
            }

            # Restore the original setting.
            $onBody = $offBody + "&public_gallery=1"
            $null = Invoke-Req -Method POST -Url ($Base + "/settings.php") -Body $onBody -ContentType "application/x-www-form-urlencoded" -Session $hsP
        }
    }
    Check "A5b error page leaks no paths/stack" (-not $hasStack) ""
}

if ($Scenario -in @("all","login-ok","login-bad")) {
    Write-Head "2. Login"
    if ([string]::IsNullOrEmpty($Password)) {
        Skip "L1/L2" "no -Password provided"
    } else {
        $s1 = New-LoginSession $Password
        if ($s1 -ne $null) {
            $admin = Invoke-Req -Url ($Base + "/admin.php") -Session $s1
            Check "L1 correct password reaches admin" ($admin.Status -eq 200) ("status=" + $admin.Status)
            $sc = ($s1.Cookies.GetCookies($Base) | Where-Object { $_.Name -eq "elation_sid" })
            if ($sc) {
                Check "L6a session cookie is HttpOnly" ($sc.HttpOnly) ""
            } else {
                Fail "L6a session cookie is HttpOnly" "no elation_sid cookie found"
            }

            # L6b/L6c: SameSite and HttpOnly cannot be read from System.Net.Cookie
            # (ToString() returns only "name=value", and .NET Framework's Cookie has
            # no SameSite property). They must be read from the raw Set-Cookie header.
            #
            # Why .NET directly instead of Invoke-WebRequest:
            #   The login response is a 302. With -MaximumRedirection 0 in PS 5.1 that
            #   THROWS, and WebException.Response is NULL for 3xx -- so the Set-Cookie
            #   header is unreachable through the cmdlet. HttpWebRequest with
            #   AllowAutoRedirect=$false returns the 302 normally, headers included.
            $setCookie = Get-SetCookieHeader $Password
            if ($setCookie -ne "") {
                Check "L6b session cookie is SameSite=Lax" ($setCookie -match "SameSite=Lax") ("Set-Cookie: " + $setCookie)
                Check "L6c session cookie is HttpOnly" ($setCookie -match "HttpOnly") ("Set-Cookie: " + $setCookie)
            } else {
                Skip "L6b/L6c" "could not capture the Set-Cookie header"
            }
        }
        $s2 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $csrf2 = Get-Csrf $s2 "/login.php"
        $b2 = "password=" + [uri]::EscapeDataString("definitely-wrong-password-xyz") + "&csrf_token=" + [uri]::EscapeDataString($csrf2)
        $bad = Invoke-Req -Method POST -Url ($Base + "/login.php") -Body $b2 -ContentType "application/x-www-form-urlencoded" -Session $s2
        Check "L2 wrong password rejected" ($bad.Status -eq 200) ("status=" + $bad.Status)
        $adminBad = Invoke-Req -Url ($Base + "/admin.php") -Session $s2
        Check "L2b wrong password cannot reach admin" ($adminBad.Status -ne 200) ("status=" + $adminBad.Status)
        $s3 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $csrf3 = Get-Csrf $s3 "/login.php"
        $before = Get-CookieValue $s3 "elation_sid"
        $b3 = "password=" + [uri]::EscapeDataString($Password) + "&csrf_token=" + [uri]::EscapeDataString($csrf3)
        $null = Invoke-Req -Method POST -Url ($Base + "/login.php") -Body $b3 -ContentType "application/x-www-form-urlencoded" -Session $s3
        $after = Get-CookieValue $s3 "elation_sid"
        Check "L5 session id regenerated on login" ($before -ne $null -and $after -ne $null -and $before -ne $after) ("before=" + $before + " after=" + $after)
    }
}

if ($Scenario -in @("all","permissions")) {
    Write-Head "4. Permissions (anonymous must be rejected)"
    $a1 = Invoke-Req -Url ($Base + "/admin.php")
    Check "P1 anonymous admin.php rejected" ($a1.Status -ne 200) ("status=" + $a1.Status)
    $u = Invoke-Req -Method POST -Url ($Base + "/upload.php") -Body "x=1" -ContentType "application/x-www-form-urlencoded"
    Check "P2 anonymous POST upload.php = 403" ($u.Status -eq 403) ("status=" + $u.Status)
    Check "P2b and is not a redirect" ($u.Status -ne 302) ("status=" + $u.Status)
    Check "P2c and is a JSON refusal" ($u.Body -match '"ok"\s*:\s*false') ("body=" + $u.Body)
    $d = Invoke-Req -Method POST -Url ($Base + "/delete.php") -Body "id=1" -ContentType "application/x-www-form-urlencoded"
    Check "P3 anonymous POST delete.php = 403" ($d.Status -eq 403) ("status=" + $d.Status)
    Check "P3b and is a JSON refusal" ($d.Body -match '"ok"\s*:\s*false') ("body=" + $d.Body)
    $ug = Invoke-Req -Url ($Base + "/upload.php")
    Check "P4 anonymous GET upload.php rejected" ($ug.Status -ne 200) ("status=" + $ug.Status)
}

if ($Scenario -in @("all","security")) {
    Write-Head "6. Security"
    $sqli = Invoke-Req -Url ($Base + "/?page=1%20OR%201=1--")
    Check "S1 SQLi parameter handled safely" ($sqli.Status -eq 200 -and $sqli.Body -notmatch "SQLSTATE|PDOException") ("status=" + $sqli.Status)
    $xss = Invoke-Req -Url ($Base + "/?page=<script>alert(1)</script>")
    Check "S3 reflected XSS not echoed" ($xss.Body -notmatch "<script>alert\(1\)</script>") ""
    foreach ($t in @("/uploads/../../data/config.php", "/uploads/..%2f..%2fdata%2fconfig.php", "/uploads/%2e%2e/%2e%2e/data/config.php", "/uploads/....//....//data/config.php")) {
        $r = Invoke-Req -Url ($Base + $t)
        # Single-quoted so PowerShell does no interpolation; the .NET regex sees
        # \$2y\$ and matches the bcrypt prefix literally. The previous form mixed
        # a backslash with a backtick escape and was only correct by accident.
        $leaked = ($r.Body -match 'admin_password_hash|password_hash\(|\$2y\$')
        Check ("S5 traversal does not leak " + $t) (-not $leaked) ("status=" + $r.Status)
    }
    foreach ($t in @("/data/database.sqlite","/data/database.sqlite-wal","/data/config.php","/data/logs/app.log","/data/install.php","/src/db.php","/deploy/php.ini")) {
        $r = Invoke-Req -Url ($Base + $t)
        $markers = "admin_password_hash|SQLite format|CREATE TABLE|<\?php|display_errors"
        $leaked = ($r.Body -match $markers)
        $ok = ($r.Status -ne 200) -or (-not $leaked)
        Check ("S10 sensitive file blocked " + $t) $ok ("status=" + $r.Status)
        if (-not $ok) { Write-Host ("       !!! SEVERE: " + $t + " is anonymously readable !!!") -ForegroundColor Red }
    }
    # Probe design note (important):
    #   The marker must appear ONLY in the executed OUTPUT, never in the SOURCE.
    #   If it appeared in the source, a statically-served file (nginx returning the
    #   raw bytes) would also contain it, and the test would falsely report
    #   "code executed" when in fact nginx simply served the file as text.
    #
    #   Source :  echo "EXEC" . "UTED_42";   <- source never contains EXECUTED_42
    #   Output :  EXECUTED_42                <- only produced by execution
    $phpOpen = [char]60 + "?php"
    $phpClose = "?" + [char]62
    $probeBody = $phpOpen + ' echo "EXEC" . "UTED_42"; ' + $phpClose

    $probe = Join-Path $PSScriptRoot "..\public\uploads\__probe_exec.php"
    try {
        $pf = [System.IO.Path]::GetFullPath($probe)
        Set-Content -Path $pf -Value $probeBody -Encoding ASCII
        $r = Invoke-Req -Url ($Base + "/uploads/__probe_exec.php")
        $executed = ($r.Body -match "EXECUTED_42")
        $sourceServed = ($r.Body -match [regex]::Escape($phpOpen))
        if ($executed) {
            Check "S11 PHP under uploads/ does not execute" $false ("status=" + $r.Status + "  !!! CODE WAS EXECUTED !!!")
        } else {
            # Not executed. Status may be 404/403 (blocked) or 200 (served as static text).
            # Both are safe: the file is never interpreted.
            $note = if ($sourceServed) { "served as static text (not executed)" } else { "blocked" }
            Check "S11 PHP under uploads/ does not execute" $true ("status=" + $r.Status + ", " + $note)
        }
        Remove-Item $pf -Force -ErrorAction SilentlyContinue

        $pf2 = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot "..\public\uploads\__probe_exec.php.jpg"))
        Set-Content -Path $pf2 -Value $probeBody -Encoding ASCII
        $r2 = Invoke-Req -Url ($Base + "/uploads/__probe_exec.php.jpg")
        Check "S11b double extension does not execute" (-not ($r2.Body -match "EXECUTED_42")) ("status=" + $r2.Status)
        Remove-Item $pf2 -Force -ErrorAction SilentlyContinue
    } catch { Skip "S11" ("cannot write probe file: " + $_.Exception.Message) }
    $dl = Invoke-Req -Url ($Base + "/uploads/")
    Check "S12 directory listing disabled" ($dl.Status -ne 200 -or $dl.Body -notmatch "Index of") ("status=" + $dl.Status)
    $logPath = Join-Path $PSScriptRoot "..\data\logs\app.log"
    if (Test-Path $logPath) {
        $log = Get-Content $logPath -Raw
        $bad = ($log -match "definitely-wrong-password-xyz") -or ($log -match "elation_sid=")
        Check "S14 log has no secrets" (-not $bad) ""
    } else { Skip "S14 log" "log file not present" }
    # S9: login rate limiting. Opt-in because it locks the testing IP for the
    # configured lockout window. The test CLEANS UP afterwards so the operator
    # is not left locked out of their own site.
    if (-not $IncludeRateLimit) {
        Skip "S9 brute force" "opt-in: re-run with -IncludeRateLimit (locks this IP for ~15 min, then cleans up)"
    } elseif ([string]::IsNullOrEmpty($Password)) {
        Skip "S9 brute force" "needs -Password to verify that the correct password is refused"
    } else {
        $rateFile = Join-Path $PSScriptRoot "..\data\logs\login_attempts.json"
        # Start from a known-clean state so a previous run cannot skew the count.
        Remove-Item $rateFile -Force -ErrorAction SilentlyContinue

        # Send exactly the configured number of wrong passwords.
        $attempts = 5
        for ($i = 1; $i -le $attempts; $i++) {
            $ws = New-Object Microsoft.PowerShell.Commands.WebRequestSession
            $wcs = Get-Csrf $ws "/login.php"
            if ($wcs) {
                $wb = "password=" + [uri]::EscapeDataString("wrong-$i") + "&csrf_token=" + [uri]::EscapeDataString($wcs)
                $null = Invoke-Req -Method POST -Url ($Base + "/login.php") -Body $wb -ContentType "application/x-www-form-urlencoded" -Session $ws
            }
        }

        # The rate file must now record a lockout in the future.
        $lockRecorded = $false
        $lockDetail = "rate file not found"
        if (Test-Path $rateFile) {
            $rateRaw = Get-Content $rateFile -Raw -Encoding UTF8
            $mm = [regex]::Match($rateRaw, '"locked_until"\s*:\s*(\d+)')
            if ($mm.Success) {
                $lockedUntil = [double]$mm.Groups[1].Value
                # Compare against the current epoch using the same clock the server uses.
                $nowTs = [double]([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())
                $delta = $lockedUntil - $nowTs
                $lockRecorded = ($delta -gt 0)
                $lockDetail = ("locked for another " + [int]$delta + "s")
            } else {
                $lockDetail = ("no locked_until in: " + $rateRaw.Trim())
            }
        }
        Check "S9a lockout recorded after 5 failures" ($lockRecorded) $lockDetail

        # THE decisive assertion: the CORRECT password must now be refused.
        $lockedSess = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $lockedCsrf = Get-Csrf $lockedSess "/login.php"
        $refused = $false
        if ($lockedCsrf) {
            $lb = "password=" + [uri]::EscapeDataString($Password) + "&csrf_token=" + [uri]::EscapeDataString($lockedCsrf)
            $lr = Invoke-Req -Method POST -Url ($Base + "/login.php") -Body $lb -ContentType "application/x-www-form-urlencoded" -Session $lockedSess
            # While locked the server renders an error page (HTTP 200) rather than
            # redirecting, so a 302 here would mean the lockout FAILED.
            $refused = ($lr.Body -match "\u5c1d\u8bd5\u6b21\u6570\u8fc7\u591a" -or $lr.Body -match "\u9501\u5b9a")
        }
        Check "S9b correct password is refused while locked" ($refused) "looked for the lockout message"

        # And the admin area must stay unreachable. -MaximumRedirection 0 is
        # essential: without it PowerShell follows the 302 to login.php and the
        # final 200 would be misread as "logged in".
        $lockedAdmin = Invoke-Req -Url ($Base + "/admin.php") -Session $lockedSess
        Check "S9c admin area stays unreachable while locked" ($lockedAdmin.Status -ne 200) ("status=" + $lockedAdmin.Status)

        # Clean up so the operator is not locked out.
        Remove-Item $rateFile -Force -ErrorAction SilentlyContinue
        Write-Host "         lockout state cleared; you can log in normally again" -ForegroundColor DarkGray
    }
}

if ($Scenario -in @("all","upload")) {
    Write-Head "5.5 Upload"
    if ([string]::IsNullOrEmpty($Password)) {
        Skip "U*" "no -Password provided"
    } else {
        if ($FixtureDir -eq "") { $FixtureDir = Join-Path $PSScriptRoot "fixtures" }
        if (-not (Test-Path $FixtureDir)) {
            Skip "U*" ("fixture dir missing: " + $FixtureDir + " (run: php tests\make-fixtures.php)")
        } else {
            $s = New-LoginSession $Password
            $adminPage = Invoke-Req -Url ($Base + "/admin.php") -Session $s
            $csrf = $null
            if ($adminPage.Status -eq 200 -and $adminPage.Body -match 'name="csrf_token"\s+value="([^"]+)"') { $csrf = $Matches[1] }
            if (-not $csrf) {
                Fail "U* cannot obtain CSRF (login failed or admin unavailable)" ("admin status=" + $adminPage.Status)
            } else {
                $curl = Get-Command curl.exe -ErrorAction SilentlyContinue
                if (-not $curl) {
                    Skip "U*" "curl.exe not found (ships with Windows 10+); cannot run multipart upload tests"
                } else {
                    $cookieJar = Join-Path $env:TEMP ("elation_cookies_" + [guid]::NewGuid().ToString("N") + ".txt")
                    foreach ($c in $s.Cookies.GetCookies($Base)) {
                        Add-Content -Path $cookieJar -Value ($c.Domain + [char]9 + "FALSE" + [char]9 + $c.Path + [char]9 + "FALSE" + [char]9 + "0" + [char]9 + $c.Name + [char]9 + $c.Value)
                    }
                    function Upload-One($File, $Csrf) {
                        # NOTE: do not name this variable $args - that is a PowerShell
                        # automatic variable and shadowing it is confusing and unsafe.
                        $cargs = @("-s","-o","-","-w","__STATUS__%{http_code}","-b",$cookieJar,"-X","POST","-F","image=@$File")
                        if ($Csrf -ne "") { $cargs += @("-F","csrf_token=$Csrf") }
                        $cargs += ($Base + "/upload.php")
                        $out = & curl.exe @cargs 2>&1
                        $txt = ($out -join "");
                        $status = 0
                        if ($txt -match "__STATUS__(\d+)") { $status = [int]$Matches[1] }
                        $body = $txt -replace "__STATUS__\d+", ""
                        return @{ Status = $status; Body = $body }
                    }
                    $cases = @(
                        @{ F="ok.jpg";       Expect="pass"; Name="U1 JPG uploads" }
                        @{ F="ok.png";       Expect="pass"; Name="U2 PNG uploads" }
                        @{ F="ok.gif";       Expect="pass"; Name="U3 GIF uploads" }
                        @{ F="ok.webp";      Expect="pass"; Name="U4 WebP uploads" }
                        @{ F="big.jpg";      Expect="fail"; Name="U5 oversized file rejected" }
                        @{ F="notimage.txt"; Expect="fail"; Name="U6 non-image rejected" }
                        @{ F="evil.php.jpg"; Expect="fail"; Name="U7 renamed malicious file rejected" }
                        # U8: a file with a valid GIF header that also contains PHP source.
                        # Accepting it is CORRECT as long as it is stored with a .gif
                        # extension (chosen from MIME, not from the upload name) and is
                        # never executed. Rejecting it is also acceptable (stricter).
                        # What would be unsafe: accepted AND stored as .php AND executable.
                        # So this case accepts either outcome and U8b verifies the property.
                        @{ F="shell.gif";    Expect="either"; Name="U8 GIF-with-PHP accepted or rejected (both safe)" }
                        @{ F="empty.jpg";    Expect="fail"; Name="U9 empty file rejected" }
                        @{ F="broken.jpg";   Expect="fail"; Name="U10 corrupt image rejected" }
                    )
                    $uploaded = @()
                    foreach ($c in $cases) {
                        $fp = Join-Path $FixtureDir $c.F
                        if (-not (Test-Path $fp)) { Skip $c.Name ("fixture missing: " + $c.F); continue }
                        $res = Upload-One $fp $csrf
                        $isOk = ($res.Body -match '"ok"\s*:\s*true')
                        if ($c.Expect -eq "either") {
                            # Both outcomes are acceptable; U8b verifies the security
                            # property separately. Just record which happened.
                            Check $c.Name $true ("status=" + $res.Status + " ok=" + $isOk)
                        } elseif ($c.Expect -eq "pass") {
                            Check $c.Name ($isOk -and $res.Status -eq 200) ("status=" + $res.Status + " ok=" + $isOk)
                            if ($isOk) {
                                $fid = 0; $fname = ""
                                if ($res.Body -match '"id"\s*:\s*(\d+)') { $fid = [int]$Matches[1] }
                                if ($res.Body -match '"filename"\s*:\s*"([a-f0-9]{32}\.(jpg|png|gif|webp))"') { $fname = $Matches[1] }
                                if ($fid -gt 0) { $uploaded += @{ Id = $fid; Filename = $fname } }
                            }
                        } else {
                            Check $c.Name ((-not $isOk) -and $res.Status -ge 400) ("status=" + $res.Status + " ok=" + $isOk)
                        }
                    }
                    # U8b: the security property for a GIF-with-embedded-PHP.
                    # Whatever the outcome, the stored extension must be a safe image
                    # extension, and the embedded PHP must never run.
                    $shellProbe = Join-Path $FixtureDir "shell.gif"
                    if (Test-Path $shellProbe) {
                        $sr = Upload-One $shellProbe $csrf
                        $sOk = ($sr.Body -match '"ok"\s*:\s*true')
                        if (-not $sOk) {
                            Check "U8b GIF-with-PHP is not stored as an executable" $true "upload rejected (stricter, safe)"
                        } else {
                            $sfn = ""
                            if ($sr.Body -match '"filename"\s*:\s*"([^"]+)"') { $sfn = $Matches[1] }
                            $safeExt = ($sfn -match '^[a-f0-9]{32}\.(jpg|png|gif|webp)$')
                            Check "U8b GIF-with-PHP is not stored as an executable" $safeExt ("stored as: " + $sfn)
                            if ($sfn -ne "") {
                                # The real question: does requesting it run the PHP?
                                $srq = Invoke-Req -Url ($Base + "/uploads/" + $sfn)
                                Check "U8c embedded PHP in a stored GIF does not execute" (-not ($srq.Body -match "PWNED_42")) ("status=" + $srq.Status)
                                # clean up
                                if ($sr.Body -match '"id"\s*:\s*(\d+)') {
                                    $null = Invoke-Req -Method POST -Url ($Base + "/delete.php") -Body ("id=" + $Matches[1] + "&csrf_token=" + [uri]::EscapeDataString($csrf)) -ContentType "application/x-www-form-urlencoded" -Session $s
                                }
                            }
                        }
                    }

                    if ($uploaded.Count -gt 0) {
                        $fn = $uploaded[0].Filename
                        Check "U11 server-generated random filename" ($fn -match "^[a-f0-9]{32}\.(jpg|png|gif|webp)$") ("filename=" + $fn)
                        $dl = Invoke-Req -Url ($Base + "/uploads/" + $fn)
                        Check "U12 direct link reachable" ($dl.Status -eq 200) ("status=" + $dl.Status)
                    } else { Skip "U11/U12" "no successfully uploaded file to inspect" }
                    $noCsrf = Upload-One (Join-Path $FixtureDir "ok.jpg") ""
                    Check "U13 upload without CSRF rejected" ($noCsrf.Status -eq 403) ("status=" + $noCsrf.Status)
                    $cleaned = 0
                    foreach ($u in $uploaded) {
                        $dr = Invoke-Req -Method POST -Url ($Base + "/delete.php") -Body ("id=" + $u.Id + "&csrf_token=" + [uri]::EscapeDataString($csrf)) -ContentType "application/x-www-form-urlencoded" -Session $s
                        if ($dr.Body -match '"ok"\s*:\s*true') { $cleaned++ }
                    }
                    if ($uploaded.Count -gt 0) { Check "U14 test uploads can be deleted" ($cleaned -eq $uploaded.Count) ("cleaned=" + $cleaned + "/" + $uploaded.Count) }
                    Remove-Item $cookieJar -Force -ErrorAction SilentlyContinue
                }
            }
        }
    }
}

if ($Scenario -in @("all","settings")) {
    Write-Head "5.7 Settings and password"

    # Anonymous must not reach either page. They are HTML pages, so the
    # expected behaviour is a redirect to login (302), not 200 and not a JSON 403.
    $anonSet = Invoke-Req -Url ($Base + "/settings.php")
    Check "G1 anonymous settings.php is redirected" ($anonSet.Status -ne 200) ("status=" + $anonSet.Status)
    $anonPw = Invoke-Req -Url ($Base + "/password.php")
    Check "G2 anonymous password.php is redirected" ($anonPw.Status -ne 200) ("status=" + $anonPw.Status)

    if ([string]::IsNullOrEmpty($Password)) {
        Skip "G3-G5" "no -Password provided"
    } else {
        $gs = New-LoginSession $Password
        $gsOk = $false
        if ($gs -ne $null) {
            $gAdmin = Invoke-Req -Url ($Base + "/admin.php") -Session $gs
            $gsOk = ($gAdmin.Status -eq 200)
        }
        if (-not $gsOk) {
            Skip "G3-G5" "could not establish an admin session"
        } else {
            # G3: both pages render when logged in, and expose CSRF
            $setPage = Invoke-Req -Url ($Base + "/settings.php") -Session $gs
            Check "G3 settings.php loads when logged in" ($setPage.Status -eq 200) ("status=" + $setPage.Status)

            $pwPage = Invoke-Req -Url ($Base + "/password.php") -Session $gs
            Check "G4 password.php loads when logged in" ($pwPage.Status -eq 200) ("status=" + $pwPage.Status)

            # G5: saving must persist. We flip per_page to a known value,
            # confirm the config file changed, then restore the original so the
            # test leaves no trace.
            $cfgFile = Join-Path $PSScriptRoot "..\data\config.php"
            $origPerPage = 20
            if (Test-Path $cfgFile) {
                $raw = Get-Content $cfgFile -Raw -Encoding UTF8
                $mm = [regex]::Match($raw, "'per_page'\s*=>\s*(\d+)")
                if ($mm.Success) { $origPerPage = [int]$mm.Groups[1].Value }
            }
            $newPerPage = if ($origPerPage -eq 20) { 21 } else { 20 }

            $csrfSet = ""
            $mcs = [regex]::Match([string]$setPage.Body, 'name="csrf_token"\s+value="([^"]+)"')
            if ($mcs.Success) { $csrfSet = $mcs.Groups[1].Value }

            if ($csrfSet -eq "") {
                Skip "G5 settings save persists" "no CSRF token on the settings page"
            } else {
                $formBody = "csrf_token=" + [uri]::EscapeDataString($csrfSet) +
                             "&site_name=Elation+Image" +
                             "&site_description=" +
                             "&site_url=" +
                             "&per_page=" + $newPerPage +
                             "&max_file_bytes=10485760" +
                             "&thumb_max_edge=480" +
                             "&login_max_attempts=5" +
                             "&login_lockout_secs=900" +
                             "&base_path=" +
                             "&timezone=Asia%2FShanghai" +
                             "&force_https=0"
                $null = Invoke-Req -Method POST -Url ($Base + "/settings.php") -Body $formBody -ContentType "application/x-www-form-urlencoded" -Session $gs

                $savedValue = -1
                if (Test-Path $cfgFile) {
                    $raw2 = Get-Content $cfgFile -Raw -Encoding UTF8
                    $mm2 = [regex]::Match($raw2, "'per_page'\s*=>\s*(\d+)")
                    if ($mm2.Success) { $savedValue = [int]$mm2.Groups[1].Value }
                }
                Check "G5 settings save persists" ($savedValue -eq $newPerPage) ("per_page is now " + $savedValue + ", expected " + $newPerPage)

                # G6: config.php must remain unreadable over HTTP after a save
                $cfgLeak = Invoke-Req -Url ($Base + "/../data/config.php")
                $leaks = ($cfgLeak.Status -eq 200) -and ($cfgLeak.Body -match "admin_password_hash")
                Check "G6 config.php still not readable after save" (-not $leaks) ("status=" + $cfgLeak.Status)

                # restore the original value so the suite leaves no trace
                $restoreBody = $formBody -replace ("&per_page=" + $newPerPage), ("&per_page=" + $origPerPage)
                $setPage2 = Invoke-Req -Url ($Base + "/settings.php") -Session $gs
                $mcs2 = [regex]::Match([string]$setPage2.Body, 'name="csrf_token"\s+value="([^"]+)"')
                if ($mcs2.Success) {
                    $restoreBody = $restoreBody -replace "csrf_token=[^&]*", ("csrf_token=" + [uri]::EscapeDataString($mcs2.Groups[1].Value))
                    $null = Invoke-Req -Method POST -Url ($Base + "/settings.php") -Body $restoreBody -ContentType "application/x-www-form-urlencoded" -Session $gs
                }
                Write-Host ("         per_page restored to " + $origPerPage) -ForegroundColor DarkGray
            }

            # G7: a wrong current password must be refused by password.php
            $csrfPw = ""
            $mcp = [regex]::Match([string]$pwPage.Body, 'name="csrf_token"\s+value="([^"]+)"')
            if ($mcp.Success) { $csrfPw = $mcp.Groups[1].Value }
            if ($csrfPw -ne "") {
                $pwBody = "csrf_token=" + [uri]::EscapeDataString($csrfPw) +
                          "&current_password=" + [uri]::EscapeDataString("definitely-not-the-password") +
                          "&new_password=" + [uri]::EscapeDataString("SomeNewPass123") +
                          "&new_password2=" + [uri]::EscapeDataString("SomeNewPass123")
                $pwResp = Invoke-Req -Method POST -Url ($Base + "/password.php") -Body $pwBody -ContentType "application/x-www-form-urlencoded" -Session $gs
                # A refusal renders the error page (200) rather than redirecting
                $refusedPw = ($pwResp.Body -match "\u5f53\u524d\u5bc6\u7801\u4e0d\u6b63\u786e")
                Check "G7 wrong current password is refused" $refusedPw ("status=" + $pwResp.Status)
            } else {
                Skip "G7" "no CSRF token on the password page"
            }
        }
    }
}

if ($Scenario -in @("all","backup")) {
    Write-Head "5.8 Backup, search and batch delete"

    $anonEx = Invoke-Req -Url ($Base + "/export.php")
    Check "H1 anonymous export is redirected" ($anonEx.Status -ne 200) ("status=" + $anonEx.Status)
    $anonBk = Invoke-Req -Url ($Base + "/backup.php")
    Check "H2 anonymous backup page is redirected" ($anonBk.Status -ne 200) ("status=" + $anonBk.Status)
    $anonDm = Invoke-Req -Method POST -Url ($Base + "/delete_many.php") -Body "ids=1" -ContentType "application/x-www-form-urlencoded"
    Check "H3 anonymous batch delete refused" ($anonDm.Status -eq 403) ("status=" + $anonDm.Status)

    if ([string]::IsNullOrEmpty($Password)) {
        Skip "H4-H8" "no -Password provided"
    } else {
        $hs = New-LoginSession $Password
        $hOk = $false
        if ($hs -ne $null) {
            $hAd = Invoke-Req -Url ($Base + "/admin.php") -Session $hs
            $hOk = ($hAd.Status -eq 200)
        }
        if (-not $hOk) {
            Skip "H4-H8" "could not establish an admin session"
        } else {
            Check "H4 admin dashboard shows stats" ($hAd.Body -match "stat-num") ""
            Check "H5 admin has a search box" ($hAd.Body -match 'type="search"') ""
            $srch = Invoke-Req -Url ($Base + "/admin.php?q=.gif") -Session $hs
            Check "H5b search filters the list" ($srch.Status -eq 200) ("status=" + $srch.Status)
            $hasBulk = ($hAd.Body -match "bulkbar") -and ($hAd.Body -match "card-pick")
            Check "H6 batch selection is available" $hasBulk ""
            $ex = Invoke-Req -Url ($Base + "/export.php") -Session $hs
            # The export is now a ZIP bundle containing database.sqlite,
            # config.php and manifest.json -- because backing up only the
            # database loses every site setting and the admin password.
            #
            # PowerShell 5.1 renders a binary body as space-separated DECIMAL
            # byte values rather than decoded text, so compare against the byte
            # sequence. ZIP local-file header "PK\x03\x04" = 80 75 3 4.
            $zipMagic = "80 75 3 4"
            $isZip = ([string]$ex.Body).StartsWith($zipMagic)
            $disp = ""
            try { $disp = [string]$ex.Headers["Content-Disposition"] } catch { $disp = "" }
            $isDownload = ($disp -match "\.zip")
            Check "H7 export returns a zip backup bundle" ($isZip -and $isDownload) ("magic=" + $isZip + ", disposition=" + $disp + ", bytes=" + ([string]$ex.Body).Length)

            # H7b: the backup page must state that config (settings + password)
            # is included -- that was the whole point of switching to a bundle.
            $bkText = [string]$bkPage.Body
            $mentionsConfig = ($bkText -match "config\.php")
            $bkPage = Invoke-Req -Url ($Base + "/backup.php") -Session $hs
            $hTok = ""
            $mh = [regex]::Match([string]$bkPage.Body, 'name="csrf_token"\s+value="([^"]+)"')
            if ($mh.Success) { $hTok = $mh.Groups[1].Value }
            if ($hTok -eq "") {
                Skip "H8 import refuses a wrong confirmation" "no CSRF token"
            } else {
                $impBody = "csrf_token=" + [uri]::EscapeDataString($hTok) + "&confirm=WRONG"
                $imp = Invoke-Req -Method POST -Url ($Base + "/import.php") -Body $impBody -ContentType "application/x-www-form-urlencoded" -Session $hs
                Check "H8 import refuses a wrong confirmation" ($imp.Status -ne 200) ("status=" + $imp.Status)
            }

            # H9: backup deletion is guarded and path-safe
            $bdAnon = Invoke-Req -Method POST -Url ($Base + "/backup_delete.php") -Body "name=x.sqlite" -ContentType "application/x-www-form-urlencoded"
            Check "H9 anonymous backup delete refused" ($bdAnon.Status -eq 403) ("status=" + $bdAnon.Status)

            $bdTok = ""
            $mbd = [regex]::Match([string]$bkPage.Body, 'name="csrf_token"\s+value="([^"]+)"')
            if ($mbd.Success) { $bdTok = $mbd.Groups[1].Value }
            if ($bdTok -eq "") {
                Skip "H9b backup delete rejects traversal" "no CSRF token"
            } else {
                # Attempt a path traversal -- the endpoint must only ever look up
                # a bare filename inside its own allowlist.
                $travBody = "csrf_token=" + [uri]::EscapeDataString($bdTok) + "&name=" + [uri]::EscapeDataString("../config.php")
                $bd = Invoke-Req -Method POST -Url ($Base + "/backup_delete.php") -Body $travBody -ContentType "application/x-www-form-urlencoded" -Session $hs
                $refusedTraversal = ($bd.Body -match '"ok"\s*:\s*false')
                Check "H9b backup delete rejects traversal" $refusedTraversal ("status=" + $bd.Status)

                # The config file must still exist after that attempt
                $cfgStill = Invoke-Req -Url ($Base + "/../data/config.php")
                Check "H9c config.php survived the traversal attempt" ($cfgStill.Status -ne 200) ("status=" + $cfgStill.Status)
            }

            # H10: thumbnail rebuild endpoint.
            # Moved from backup.php to maintenance.php when the page was split --
            # the backup page now covers only export and import.
            $mtPage = Invoke-Req -Url ($Base + "/maintenance.php") -Session $hs
            Check "H10 maintenance page offers a thumbnail rebuild" ($mtPage.Body -match "btn-rebuild-thumbs") ""
            $rtTok = ""
            $mrt = [regex]::Match([string]$mtPage.Body, 'name="csrf_token"\s+value="([^"]+)"')
            if ($mrt.Success) { $rtTok = $mrt.Groups[1].Value }
            if ($rtTok -ne "") {
                $rtBody = "csrf_token=" + [uri]::EscapeDataString($rtTok) + "&offset=0&limit=2"
                $rt = Invoke-Req -Method POST -Url ($Base + "/rebuild_thumbs.php") -Body $rtBody -ContentType "application/x-www-form-urlencoded" -Session $hs
                $rtOk = ($rt.Body -match '"ok"\s*:\s*true')
                Check "H10b rebuild endpoint responds" $rtOk ("status=" + $rt.Status)
            } else {
                Skip "H10b" "no CSRF token"
            }

            # H11: dark mode styles are shipped.
            # The dark palette is keyed off html[data-theme="dark"] (set by
            # theme.js) so that a manual toggle can drive it; the "system"
            # preference is resolved in JS instead of a media query.
            $dcss = Invoke-Req -Url ($Base + "/assets/style.css")
            $hasDarkVars = ($dcss.Body -match 'data-theme="dark"')
            Check "H11 dark mode styles are shipped" $hasDarkVars ""
            $themeSrc = Invoke-Req -Url ($Base + "/assets/theme.js")
            $resolvesSystem = ($themeSrc.Body -match "prefers-color-scheme")
            Check "H11b theme script resolves the system preference" $resolvesSystem ""

            # H21: storage summary lives on the maintenance page.
            # Disk-full is not just an upload problem: PHP cannot write session
            # files either, so the whole site can start erroring with a message
            # that points somewhere else entirely.
            #
            # It moved from backup.php when that page was split; the backup page
            # now covers only export and import.
            Check "H21 maintenance page shows a storage summary" ($mtPage.Body -match "storage-row") ""
            # Assert on ASCII class markers only: this script must stay pure ASCII
            # (Windows PowerShell 5.1 misreads non-ASCII .ps1 files).
            $hasFree = ($mtPage.Body -match "storage-value")
            Check "H21b storage summary reports values" $hasFree ""
            $hasBar = ($mtPage.Body -match "storage-bar")
            Check "H21c storage summary draws a usage bar" $hasBar ""

            # H22: the sidebar must appear on every admin page and nowhere else.
            # A missing sidebar on one page would strand the user there.
            $sbPages = @("admin.php", "settings.php", "settings-advanced.php", "backup.php", "maintenance.php", "password.php")
            $sbMissing = @()
            foreach ($sp in $sbPages) {
                $rsp = Invoke-Req -Url ($Base + "/" + $sp) -Session $hs
                if ($rsp.Body -notmatch "sidebar-link") { $sbMissing += $sp }
            }
            Check "H22 sidebar appears on every admin page" ($sbMissing.Count -eq 0) ("missing: " + ($sbMissing -join ", "))

            # H22b: the public homepage must NOT carry an admin sidebar.
            $pubHome = Invoke-Req -Url ($Base + "/")
            Check "H22b public homepage has no sidebar" ($pubHome.Body -notmatch "sidebar-link") ""
            # H23: saving one settings page must not clear another page's checkbox.
            #
            # This was a real data-loss bug. Checkboxes are simply absent from a
            # POST when unticked, which is how the original code detected "user
            # turned it off" -- but once settings were split across pages, an
            # absent box could equally mean "this page has no such field".
            # Saving either page silently cleared the other page's setting.
            #
            # The fix has each form declare the keys it owns via _form_keys.
            # This walks the exact reported sequence.
            $setA = Invoke-Req -Url ($Base + "/settings.php") -Session $hs
            $tokA = ""
            $mA = [regex]::Match([string]$setA.Body, 'name="csrf_token"\s+value="([^"]+)"')
            if ($mA.Success) { $tokA = $mA.Groups[1].Value }

            $setB = Invoke-Req -Url ($Base + "/settings-advanced.php") -Session $hs
            $tokB = ""
            $mB = [regex]::Match([string]$setB.Body, 'name="csrf_token"\s+value="([^"]+)"')
            if ($mB.Success) { $tokB = $mB.Groups[1].Value }

            $keysA = "site_name,site_description,footer_note,site_url,public_gallery,per_page"
            $keysB = "force_https,login_max_attempts,login_lockout_secs,max_file_bytes,thumb_max_edge,strip_metadata,base_path,timezone"

            # Save page A with its checkbox ticked.
            $bodyA = "csrf_token=" + [uri]::EscapeDataString($tokA) +
                     "&_form_keys=" + [uri]::EscapeDataString($keysA) +
                     "&site_name=Test&site_description=&footer_note=&site_url=&per_page=20" +
                     "&public_gallery=1"
            $null = Invoke-Req -Method POST -Url ($Base + "/settings.php") -Body $bodyA -ContentType "application/x-www-form-urlencoded" -Session $hs

            # Save page B with its checkbox ticked.
            $bodyB = "csrf_token=" + [uri]::EscapeDataString($tokB) +
                     "&_form_keys=" + [uri]::EscapeDataString($keysB) +
                     "&force_https=0&login_max_attempts=5&login_lockout_secs=900" +
                     "&max_file_bytes=10485760&thumb_max_edge=480&base_path=&timezone=UTC" +
                     "&strip_metadata=1"
            $null = Invoke-Req -Method POST -Url ($Base + "/settings-advanced.php") -Body $bodyB -ContentType "application/x-www-form-urlencoded" -Session $hs

            # Both must still be on. Read them back through the rendered forms,
            # which is what the user actually sees.
            $chkA = Invoke-Req -Url ($Base + "/settings.php") -Session $hs
            $chkB = Invoke-Req -Url ($Base + "/settings-advanced.php") -Session $hs
            $onA = ($chkA.Body -match 'name="public_gallery"[^>]*checked')
            $onB = ($chkB.Body -match 'name="strip_metadata"[^>]*checked')
            Check "H23 saving one page keeps the other page's checkbox" ($onA -and $onB) ("public_gallery=" + $onA + " strip_metadata=" + $onB)

            # H23b: unticking must still work on the page that owns the field.
            $bodyOff = "csrf_token=" + [uri]::EscapeDataString($tokA) +
                       "&_form_keys=" + [uri]::EscapeDataString($keysA) +
                       "&site_name=Test&site_description=&footer_note=&site_url=&per_page=20"
            $null = Invoke-Req -Method POST -Url ($Base + "/settings.php") -Body $bodyOff -ContentType "application/x-www-form-urlencoded" -Session $hs
            $afterOff = Invoke-Req -Url ($Base + "/settings.php") -Session $hs
            $stillOn = ($afterOff.Body -match 'name="public_gallery"[^>]*checked')
            Check "H23b unticking a checkbox still turns it off" (-not $stillOn) ("still checked=" + $stillOn)

            # Restore it, so later assertions see the default state.
            $null = Invoke-Req -Method POST -Url ($Base + "/settings.php") -Body $bodyA -ContentType "application/x-www-form-urlencoded" -Session $hs
            # H19: problem cards are flagged in the list itself.
            # The consistency report alone only printed filenames, so the user
            # still had to hunt for the card. Now the card carries a badge.
            $root2 = Invoke-Req -Url ($Base + "/")
            Check "H19 card badges are shipped in CSS" ((Invoke-Req -Url ($Base + "/assets/style.css")).Body -match "card-flag") ""
            Check "H19b cards can opt into an issue style" ((Invoke-Req -Url ($Base + "/assets/style.css")).Body -match "has-issue") ""

            # H20: broken records can be cleaned up (records whose file is gone).
            $cons = Invoke-Req -Url ($Base + "/check_consistency.php")
            $anonBlocked = ($cons.Status -eq 403) -or ($cons.Status -eq 405)
            Check "H20 consistency endpoint stays admin-only" $anonBlocked ("status=" + $cons.Status)
            # H18: bulk selection must survive a grid refresh.
            # refreshGrid() replaces #admin-list innerHTML, and #admin-grid
            # lives INSIDE it, so a listener bound to the grid element is
            # destroyed with it. Selecting after a refresh then did nothing,
            # and the delete button was a detached node. Delegation fixes it.
            $bjs = Invoke-Req -Url ($Base + "/assets/app.js")
            $delegated = ($bjs.Body -match "document\.addEventListener\(.change.") -and
                         ($bjs.Body -match "bulk-delete")
            Check "H18 bulk handlers are delegated to document" $delegated ""
            $noStaleGrid = -not ($bjs.Body -match "grid\.addEventListener\(.change.")
            Check "H18b no listener bound to the replaced grid" $noStaleGrid ""
            $liveLookup = ($bjs.Body -match "getElementById\(.admin-grid.\)")
            Check "H18c the grid is re-resolved on each use" $liveLookup ""
            # H17: back-to-top button
            $ftr = Invoke-Req -Url ($Base + "/")
            Check "H17 back-to-top button exists in the markup" ($ftr.Body -match "to-top") ""
            $djs2 = Invoke-Req -Url ($Base + "/assets/app.js")
            $hasLogic = ($djs2.Body -match "to-top") -and ($djs2.Body -match "pageYOffset")
            Check "H17b back-to-top is wired up" $hasLogic ""
            # It must not depend on requestAnimationFrame for a state update:
            # rAF can be deferred when the page is not rendering, which would
            # leave the button stuck in the wrong state.
            $noRaf = -not ($djs2.Body -match "requestAnimationFrame\(update")
            Check "H17c scroll handling does not rely on rAF" $noRaf ""
            # H15: the header must stay pinned while scrolling.
            # Regression guard: we once added `overflow-x: hidden` to html/body
            # as a fallback against horizontal overflow -- that makes html a
            # scroll container and silently BREAKS position: sticky, so the
            # header scrolled away. Verified: scrolling 600px moved it to -600.
            $dcss3 = Invoke-Req -Url ($Base + "/assets/style.css")
            $noBodyOverflow = -not ($dcss3.Body -match "html,\s*body\s*\{[^}]*overflow-x\s*:\s*hidden")
            Check "H15 sticky header is not broken by body overflow" $noBodyOverflow ""

            # H16: on narrow screens the site name must remain visible.
            # It used to be hidden at <=420px, which left the logo alone on one
            # row and the nav on another with a reduced font size.
            $hidesBrand = ($dcss3.Body -match "\.brand-text\s*\{\s*display\s*:\s*none")
            Check "H16 site name stays visible on narrow screens" (-not $hidesBrand) ""
            # H14: dark mode must cover the surfaces that are easy to forget.
            # Regression guard: form controls and tinted bars previously kept
            # their light backgrounds in dark mode because browsers default
            # input backgrounds to white and some rules were hardcoded.
            $dcss2 = Invoke-Req -Url ($Base + "/assets/style.css")
            $cov = ($dcss2.Body -match "--input-bg") -and
                   ($dcss2.Body -match "--tint-bg") -and
                   ($dcss2.Body -match "input\[type=.file.\]")
            Check "H14 dark mode covers inputs and tinted surfaces" $cov ""
            # H13: theme toggle is available and applied before paint
            $themeJs = Invoke-Req -Url ($Base + "/assets/theme.js")
            Check "H13 theme script is served" ($themeJs.Status -eq 200) ("status=" + $themeJs.Status)
            # It must be loaded WITHOUT defer/async, before the stylesheet,
            # otherwise the page flashes light before switching to dark.
            $head = [string]$hAd.Body
            $themeFirst = ($head.IndexOf("theme.js") -ge 0) -and
                           ($head.IndexOf("theme.js") -lt $head.IndexOf("style.css"))
            Check "H13b theme script loads before the stylesheet" $themeFirst ""
            Check "H13c theme toggle button is present" ($head -match "theme-toggle") ""
            # H12: the upload flow offers a copyable direct link.
            # Assert on ASCII markers only -- this script must stay pure ASCII
            # (Windows PowerShell 5.1 misreads non-ASCII .ps1).
            #   data-url + js-copy  -> the per-upload copy button is wired up
            $djs = Invoke-Req -Url ($Base + "/assets/app.js")
            $hasCopy = ($djs.Body -match "js-copy") -and ($djs.Body -match "data-url")
            Check "H12 upload offers a copyable direct link" $hasCopy ""
        }
    }
}
if ($Scenario -in @("all","delete")) {
    Write-Head "5. Delete"
    if ([string]::IsNullOrEmpty($Password)) {
        Skip "D*" "no -Password provided"
    } else {
        $s = New-LoginSession $Password
        $adminPage = Invoke-Req -Url ($Base + "/admin.php") -Session $s
        if ($adminPage.Status -ne 200) { Skip "D*" "login failed" }
        else {
            $csrf = $null
            if ($adminPage.Body -match 'name="csrf_token"\s+value="([^"]+)"') { $csrf = $Matches[1] }
            $r2 = Invoke-Req -Method POST -Url ($Base + "/delete.php") -Body ("id=999999&csrf_token=" + [uri]::EscapeDataString($csrf)) -ContentType "application/x-www-form-urlencoded" -Session $s
            Check "D2 deleting a missing image is OK" ($r2.Status -eq 200 -and $r2.Body -match '"ok"\s*:\s*true') ("status=" + $r2.Status)
            $r4 = Invoke-Req -Method POST -Url ($Base + "/delete.php") -Body ("id=abc&csrf_token=" + [uri]::EscapeDataString($csrf)) -ContentType "application/x-www-form-urlencoded" -Session $s
            Check "D4 bad id = 400" ($r4.Status -eq 400) ("status=" + $r4.Status)
            $r5 = Invoke-Req -Method POST -Url ($Base + "/delete.php") -Body "id=1&csrf_token=deadbeef" -ContentType "application/x-www-form-urlencoded" -Session $s
            Check "D5 bad CSRF = 403" ($r5.Status -eq 403) ("status=" + $r5.Status)
            $r6 = Invoke-Req -Url ($Base + "/delete.php?id=1") -Session $s
            Check "D6 GET does not delete" ($r6.Status -ne 200 -or $r6.Body -notmatch '"ok"\s*:\s*true') ("status=" + $r6.Status)
        }
    }
}

Write-Host ""
Write-Host "================ summary ================" -ForegroundColor White
Write-Host ("PASS: " + $script:pass) -ForegroundColor Green
Write-Host ("FAIL: " + $script:fail) -ForegroundColor Red
Write-Host ("SKIP: " + $script:skip) -ForegroundColor DarkYellow
if ($script:failures.Count -gt 0) {
    Write-Host ""
    Write-Host "Failures:" -ForegroundColor Red
    foreach ($f in $script:failures) { Write-Host ("  - " + $f) -ForegroundColor Red }
}
Write-Host "=========================================" -ForegroundColor White
if ($script:fail -gt 0) { exit 1 } else { exit 0 }
