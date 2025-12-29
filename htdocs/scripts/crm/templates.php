<?php
// Custom email templates for CRM automations. Edit safely; existing templates remain unchanged.
// Placeholders: {name}, {date}
return [
    'renewal' => [
        'subject' => 'Aviso de renovação do certificado digital',
        'html' => <<<HTML
Olá {name},<br><br>
Seu certificado digital vence em {date}. Responda este e-mail ou fale conosco para renovar sem interrupções.<br><br>
Equipe Safegreen
HTML,
        'text' => <<<TEXT
Olá {name},

Seu certificado digital vence em {date}. Responda este e-mail ou fale conosco para renovar sem interrupções.

Equipe Safegreen
TEXT,
    ],
    'birthday' => [
        'subject' => 'Feliz aniversário!',
        'html' => <<<HTML
Olá {name},<br><br>
Feliz aniversário! Que seu dia seja incrível. Conte sempre com a equipe Safegreen.<br><br>
Um abraço!
HTML,
        'text' => <<<TEXT
Olá {name},

Feliz aniversário! Que seu dia seja incrível. Conte sempre com a equipe Safegreen.

Um abraço!
TEXT,
    ],
];
