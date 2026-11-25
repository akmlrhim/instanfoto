Get-CimInstance Win32_Process | Where-Object {
  ($_.CommandLine -like '*api/public/router.php*') -or
  ($_.CommandLine -like '*vite.js*--host*') -or
  ($_.CommandLine -like '*uploader/index.js*') -or
  ($_.CommandLine -like '*phone-drop.js*')
} | ForEach-Object {
  Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
}

Write-Host 'Proses demo dihentikan.'
