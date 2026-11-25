param(
  [string]$Ip = ''
)

$root = Split-Path -Parent $PSScriptRoot

if (-not (Test-Path (Join-Path $root 'dist/index.html'))) {
  Write-Host 'Jalankan npm run build dulu agar panel tersedia.'
  exit 1
}

$activeInterfaces = Get-NetAdapter |
  Where-Object { $_.Status -eq 'Up' } |
  Select-Object -ExpandProperty InterfaceIndex

$addresses = Get-NetIPAddress -AddressFamily IPv4 |
  Where-Object {
    $_.InterfaceIndex -in $activeInterfaces -and
    $_.IPAddress -notlike '127.*' -and
    $_.IPAddress -notlike '169.254.*'
  } |
  Select-Object -ExpandProperty IPAddress -Unique

if ($addresses.Count -eq 0) {
  $addresses = Get-NetIPAddress -AddressFamily IPv4 |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
    Select-Object -ExpandProperty IPAddress -Unique
}

if ($Ip -eq '') {
  $Ip = $addresses | Select-Object -First 1
}

if ($Ip -eq '') {
  Write-Host 'Tidak ada alamat IP LAN. Hubungkan laptop ke jaringan yang sama dengan HP.'
  exit 1
}

$env:BASE_URL = "http://$($Ip):5173"

Write-Host "Alamat IP terdeteksi: $($addresses -join ', ')"
Write-Host "QR akan membuka: $env:BASE_URL/a/{id}"
Write-Host 'Panel: http://127.0.0.1:4000/panel'
Write-Host "Kirim foto dari HP: http://$($Ip):4001/phone"
Write-Host "Folder foto HP: $(Join-Path $root '.demo/foto-hp')"

foreach ($port in @(5173, 4001)) {
  try {
    New-NetFirewallRule -DisplayName "InstanFoto Demo $port" -Direction Inbound -Protocol TCP -LocalPort $port -Action Allow -Profile Private -ErrorAction Stop | Out-Null
  } catch {
    Write-Host "Port $port mungkin perlu diizinkan manual di firewall Windows."
  }
}

$php = (Get-Command php).Source

Start-Process -FilePath $php `
  -ArgumentList @('-S', '127.0.0.1:8000', '-t', 'api/public', 'api/public/router.php') `
  -WorkingDirectory $root -WindowStyle Hidden

Start-Process -FilePath $node `
  -ArgumentList @('node_modules/vite/bin/vite.js', '--host', '0.0.0.0') `
  -WorkingDirectory $root -WindowStyle Hidden

Start-Process -FilePath $node `
  -ArgumentList @('uploader/index.js') `
  -WorkingDirectory $root -WindowStyle Hidden

Start-Process -FilePath $node `
  -ArgumentList @('demo/phone-drop.js') `
  -WorkingDirectory $root -WindowStyle Hidden

Write-Host 'Lokal siap. Jalankan demo/stop-local.ps1 untuk menghentikan.'
