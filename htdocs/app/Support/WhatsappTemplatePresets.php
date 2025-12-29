<?php

declare(strict_types=1);

namespace App\Support;

final class WhatsappTemplatePresets
{
    public const TEMPLATE_KEYS = ['birthday', 'renewal', 'rescue'];

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'birthday' => "Olá, {{nome}}! 🎉\nFeliz aniversário! Que este dia seja repleto de alegria e que você conquiste muitos sonhos. Conte sempre com a equipe da AR SafeGreen Certificado Digital!",
            'renewal' => "Olá, {{nome}}! Aqui é da AR SafeGreen Certificado Digital.\nSeu certificado {{empresa}} vence em {{vencimento}}. Vamos agendar agora para garantir a continuidade dos seus serviços?",
            'rescue' => "Olá, {{nome}}! Percebemos que você ainda não concluiu a renovação do certificado. Posso ajudar com os próximos passos para agilizar tudo hoje?",
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'birthday' => 'Mensagens de aniversário',
            'renewal' => 'Mensagem de renovação',
            'rescue' => 'Mensagem de resgate/reativação',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function placeholderHints(): array
    {
        return [
            '{{nome}}' => 'Nome do titular (ou razão social se não houver titular)',
            '{{empresa}}' => 'Razão social / nome do cliente',
            '{{documento}}' => 'Documento formatado do cliente (CPF ou CNPJ)',
            '{{cpf}}' => 'CPF do titular',
            '{{cnpj}}' => 'CNPJ do cliente',
            '{{titular_documento}}' => 'Documento do titular (quando existir)',
            '{{data_nascimento}}' => 'Data de nascimento do titular',
            '{{vencimento}}' => 'Data do último certificado conhecido',
            '{{status}}' => 'Status atual na carteira',
        ];
    }

    public static function default(string $key): string
    {
        $defaults = self::defaults();
        return $defaults[$key] ?? '';
    }
}
