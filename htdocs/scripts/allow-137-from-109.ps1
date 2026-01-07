# Requer PowerShell aberto como Administrador no host 192.168.1.137
# Objetivo: garantir que o 137 aceite tráfego e ping vindo de 192.168.1.109.

Write-Host "=== Ajustando perfil da rede para Privado ==="
$profile = Get-NetConnectionProfile | Where-Object { $_.IPv4Connectivity -ne 'None' } | Select-Object -First 1
if ($profile) {
    Set-NetConnectionProfile -InterfaceIndex $profile.InterfaceIndex -NetworkCategory Private -ErrorAction SilentlyContinue
    Write-Host "Perfil ajustado em '$($profile.Name)'"
} else {
    Write-Warning "Nenhum perfil de rede ativo encontrado."
}

Write-Host "=== Liberando ICMP (ping) do 192.168.1.109 ==="
netsh advfirewall firewall add rule name="Allow ICMPv4 from 192.168.1.109" dir=in action=allow protocol=icmpv4:8,any remoteip=192.168.1.109 | Out-Null

Write-Host "=== Liberando portas HTTP/Gateways para 192.168.1.109 ==="
$ports = @(80,4010,4020,4030,8443)
foreach ($p in $ports) {
    netsh advfirewall firewall delete rule name="Allow TCP $p from 192.168.1.109" dir=in protocol=TCP localport=$p remoteip=192.168.1.109 | Out-Null
    netsh advfirewall firewall add rule name="Allow TCP $p from 192.168.1.109" dir=in action=allow protocol=TCP localport=$p remoteip=192.168.1.109 | Out-Null
}

Write-Host "=== Habilitando grupo de compartilhamento de arquivos/impressoras ==="
netsh advfirewall firewall set rule group="Compartilhamento de Arquivos e Impressoras" new enable=Yes | Out-Null

Write-Host "=== Resumo rápido de ouvintes nas portas alvo ==="
foreach ($p in $ports) {
    $listener = netstat -ano | Select-String ":${p} "
    if ($listener) {
        Write-Host "Porta ${p}: ouvindo"
    } else {
        Write-Warning "Porta ${p}: nada ouvindo"
    }
}

Write-Host "Concluído. Agora teste do 109: ping 192.168.1.137 e acessar http://192.168.1.137/ e http://192.168.1.137:4010/health"
