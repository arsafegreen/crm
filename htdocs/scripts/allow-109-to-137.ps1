# Requer PowerShell aberto como Administrador no host 192.168.1.109
# Objetivo: garantir que o 109 consiga alcançar o 137 (HTTP/WhatsApp gateways) e responder a ping.

Write-Host "=== Ajustando perfil da rede para Privado ==="
$profile = Get-NetConnectionProfile | Where-Object { $_.IPv4Connectivity -ne 'None' } | Select-Object -First 1
if ($profile) {
    Set-NetConnectionProfile -InterfaceIndex $profile.InterfaceIndex -NetworkCategory Private -ErrorAction SilentlyContinue
    Write-Host "Perfil ajustado em '$($profile.Name)'"
} else {
    Write-Warning "Nenhum perfil de rede ativo encontrado."
}

Write-Host "=== Liberando ICMP (ping) e compartilhamento de arquivos/impressoras ==="
netsh advfirewall firewall add rule name="Allow ICMPv4 In" dir=in action=allow protocol=icmpv4:8,any | Out-Null
netsh advfirewall firewall set rule group="Compartilhamento de Arquivos e Impressoras" new enable=Yes | Out-Null

Write-Host "=== Limpando ARP e fixando ARP estático para 192.168.1.137 ==="
# MAC do Wi-Fi do host 192.168.1.137 (vista no ipconfig do 137)
$targetIp = "192.168.1.137"
$targetMac = "10-F6-0A-AC-9D-51"
arp -d $targetIp | Out-Null
arp -s $targetIp $targetMac

Write-Host "=== Testando conectividade com 192.168.1.137 ==="
ping -n 3 $targetIp

Write-Host "Concluído. Se ainda não responder, verificar VPN/antivírus bloqueando LAN e isolamento no Wi-Fi."
