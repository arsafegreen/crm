<?php
/** @var \DateTimeImmutable $weekStart */
/** @var \DateTimeImmutable $weekEnd */
/** @var \DateTimeImmutable $rangeStart */
/** @var \DateTimeImmutable $rangeEnd */
/** @var \DateTimeImmutable $referenceDate */
/** @var array{client_name:string,client_document:string,auto_open:bool} $prefillAppointment */
/** @var array<string, array<int, array>> $appointmentsByDay */
/** @var array<int, array> $scheduleMatrix */
/** @var array<int, array{id:int,name:string,email:string,role:string}> $avpOptions */
/** @var \App\Auth\AuthenticatedUser $currentUser */

$timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
$dayFormatter = static fn(string $dayKey): string => (new DateTimeImmutable($dayKey, $timezone))->format('d/m (D)');
$feedback = $_SESSION['agenda_feedback'] ?? null;
unset($_SESSION['agenda_feedback']);
$dayNames = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
$selectedAvpId = isset($selectedAvpId) && $selectedAvpId !== '' ? (int)$selectedAvpId : null;
$canSwitchAvp = !empty($canSwitchAvp);
$canManageAgenda = !empty($canManageAgenda);
$hasFullAgendaAccess = !empty($hasFullAgendaAccess);
$statusLabels = [
    'scheduled' => 'Agendado',
    'confirmed' => 'Confirmado',
    'done' => 'Realizado',
    'completed' => 'Concluído',
    'canceled' => 'Cancelado',
];
$defaultOwnerId = isset($defaultOwnerId) ? (int)$defaultOwnerId : ($selectedAvpId ?? 0);
$rangeType = isset($rangeType) ? (string)$rangeType : 'week';
$rangeLabel = isset($rangeLabel) ? (string)$rangeLabel : 'semana';
$referenceDate = isset($referenceDate) && $referenceDate instanceof DateTimeImmutable ? $referenceDate : $weekStart;
$rangeStart = isset($rangeStart) && $rangeStart instanceof DateTimeImmutable ? $rangeStart : $weekStart;
$rangeEnd = isset($rangeEnd) && $rangeEnd instanceof DateTimeImmutable ? $rangeEnd : $weekEnd;
$prefillAppointment = $prefillAppointment ?? ['client_name' => '', 'client_document' => '', 'auto_open' => false];
$standalone = !empty($standalone);
?>

<style>
    .agenda-date-stepper {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .agenda-step-button {
        border: 1px solid rgba(148, 163, 184, 0.35);
        background: rgba(15, 23, 42, 0.6);
        color: var(--text);
        width: 34px;
        height: 34px;
        border-radius: 50%;
        font-size: 1.1rem;
        cursor: pointer;
        transition: border-color 0.15s ease, color 0.15s ease;
    }
    .agenda-step-button:hover {
        border-color: var(--accent);
        color: var(--accent);
    }
    .agenda-switch {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        user-select: none;
    }
    .agenda-switch input {
        appearance: none;
        -webkit-appearance: none;
        width: 40px;
        height: 22px;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.4);
        border: 1px solid rgba(148, 163, 184, 0.5);
        position: relative;
        transition: background 0.2s ease, border-color 0.2s ease;
    }
    .agenda-switch input::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 2px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #0f172a;
        transition: transform 0.2s ease;
    }
    .agenda-switch input:checked {
        background: rgba(248, 113, 113, 0.4);
        border-color: rgba(248, 113, 113, 0.9);
    }
    .agenda-switch input:checked::after {
        transform: translateX(18px);
    }
    .agenda-switch-status {
        font-size: 0.8rem;
        color: var(--muted);
        min-width: 52px;
    }
    [data-day-row][data-day-closed="1"] {
        opacity: 0.6;
    }
</style>

<header>
    <div>
        <h1>Agenda operacional</h1>
        <p>Controle de agendamentos integrados ao funil e visibilidade por AVP.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
        <?php if (!$standalone): ?>
            <a href="<?= url('crm/clients'); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar para carteira</a>
        <?php endif; ?>
        <?php if ($canManageAgenda): ?>
            <button type="button" class="primary" data-agenda-open-modal style="padding:10px 18px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:1.1rem;line-height:1;">+</span>
                Novo agendamento
            </button>
        <?php endif; ?>
    </div>
</header>

<?php if ($feedback !== null): ?>
    <div class="panel" style="margin-bottom:20px;border-left:4px solid <?= ($feedback['type'] ?? '') === 'success' ? '#22c55e' : '#38bdf8'; ?>;">
        <strong style="display:block;margin-bottom:6px;">Agenda</strong>
        <p style="margin:0;color:var(--muted);"><?= htmlspecialchars((string)($feedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
<?php endif; ?>

<div class="panel" style="margin-bottom:24px;display:flex;flex-wrap:wrap;gap:18px;align-items:center;justify-content:space-between;">
    <div>
        <strong>Período selecionado (<?= htmlspecialchars(ucfirst($rangeLabel), ENT_QUOTES, 'UTF-8'); ?>):</strong>
        <span style="color:var(--muted);">
            <?= $rangeStart->format('d/m/Y'); ?> &rarr; <?= $rangeEnd->format('d/m/Y'); ?>
        </span>
    </div>
    <form method="get" action="<?= url('agenda'); ?>" style="display:flex;gap:10px;align-items:center;" data-agenda-range-form>
        <?php if ($canSwitchAvp && $avpOptions !== []): ?>
            <label style="display:flex;flex-direction:column;font-size:0.9rem;color:var(--muted);">
                Colaborador
                <select name="avp" style="min-width:220px;padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <?php if ($hasFullAgendaAccess): ?>
                        <option value="0" <?= $selectedAvpId === null ? 'selected' : ''; ?>>Todos os AVPs</option>
                    <?php endif; ?>
                    <?php foreach ($avpOptions as $avp): ?>
                        <option value="<?= (int)$avp['id']; ?>" <?= $selectedAvpId === (int)$avp['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($avp['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label style="display:flex;flex-direction:column;font-size:0.9rem;color:var(--muted);">
            Referência
            <div class="agenda-date-stepper">
                <button type="button" class="agenda-step-button" data-range-step="-1" aria-label="Período anterior">&minus;</button>
                <input type="date" name="date" data-range-reference value="<?= htmlspecialchars($referenceDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <button type="button" class="agenda-step-button" data-range-step="1" aria-label="Próximo período">+</button>
            </div>
        </label>
        <label style="display:flex;flex-direction:column;font-size:0.9rem;color:var(--muted);">
            Intervalo
            <select name="range" data-range-type style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);min-width:140px;">
                <option value="day" <?= $rangeType === 'day' ? 'selected' : ''; ?>>Dia</option>
                <option value="week" <?= $rangeType === 'week' ? 'selected' : ''; ?>>Semana</option>
                <option value="month" <?= $rangeType === 'month' ? 'selected' : ''; ?>>Mês</option>
                <option value="year" <?= $rangeType === 'year' ? 'selected' : ''; ?>>Ano</option>
            </select>
        </label>
        <?php if (!$canSwitchAvp && $selectedAvpId): ?>
            <input type="hidden" name="avp" value="<?= (int)$selectedAvpId; ?>">
        <?php endif; ?>
        <button class="primary" type="submit" style="padding:8px 18px;">Aplicar</button>
    </form>
</div>

<script>
    (function () {
        var rangeForm = document.querySelector('[data-agenda-range-form]');
        if (!rangeForm) {
            return;
        }

        var dateField = rangeForm.querySelector('[data-range-reference]');
        var rangeField = rangeForm.querySelector('[data-range-type]');
        var stepButtons = rangeForm.querySelectorAll('[data-range-step]');

        stepButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var direction = parseInt(button.getAttribute('data-range-step') || '0', 10) || 0;
                if (direction === 0) {
                    return;
                }
                adjustReference(direction);
            });
        });

        function adjustReference(direction) {
            if (!dateField) {
                return;
            }

            var currentDate = parseInputDate(dateField.value) || new Date();
            var rangeTypeField = rangeField ? rangeField.value : 'week';
            var nextDate = new Date(currentDate.getTime());

            switch (rangeTypeField) {
                case 'day':
                    nextDate.setDate(nextDate.getDate() + direction);
                    break;
                case 'week':
                    nextDate.setDate(nextDate.getDate() + (direction * 7));
                    break;
                case 'month':
                    nextDate.setMonth(nextDate.getMonth() + direction);
                    break;
                case 'year':
                    nextDate.setFullYear(nextDate.getFullYear() + direction);
                    break;
                default:
                    nextDate.setDate(nextDate.getDate() + direction);
                    break;
            }

            dateField.value = formatDateValue(nextDate);
            dateField.dispatchEvent(new Event('change'));
            submitRangeForm();
        }

        function parseInputDate(value) {
            if (!value) {
                return null;
            }
            var parsed = new Date(value + 'T00:00:00');
            return Number.isNaN(parsed.getTime()) ? null : parsed;
        }

        function formatDateValue(date) {
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var day = String(date.getDate()).padStart(2, '0');
            return date.getFullYear() + '-' + month + '-' + day;
        }

        function submitRangeForm() {
            if (typeof rangeForm.requestSubmit === 'function') {
                rangeForm.requestSubmit(rangeForm.querySelector('button[type="submit"]'));
            } else {
                rangeForm.submit();
            }
        }
    })();
</script>

<div class="panel" style="margin-bottom:24px;">
    <h2 style="margin-top:0;">Agendamentos (<?= htmlspecialchars(ucfirst($rangeLabel), ENT_QUOTES, 'UTF-8'); ?>)</h2>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;min-width:720px;">
            <thead>
                <tr style="background:rgba(15,23,42,0.65);">
                    <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.3);">Data</th>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.3);">Horário</th>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.3);">Cliente</th>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.3);">AVP</th>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.3);">Categoria</th>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid rgba(148,163,184,0.3);">Status</th>
                    <?php if ($canManageAgenda): ?>
                        <th style="text-align:right;padding:10px;border-bottom:1px solid rgba(148,163,184,0.3);width:110px;">Ações</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($appointmentsByDay === []): ?>
                <tr>
                    <td colspan="6" style="padding:16px;color:var(--muted);text-align:center;">Nenhum agendamento registrado neste <?= htmlspecialchars(ucfirst($rangeLabel), ENT_QUOTES, 'UTF-8'); ?>.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($appointmentsByDay as $day => $items): ?>
                    <?php foreach ($items as $index => $item):
                        $startAt = (new DateTimeImmutable('@' . (int)($item['starts_at'] ?? 0)))->setTimezone($timezone);
                        $endAt = (new DateTimeImmutable('@' . (int)($item['ends_at'] ?? 0)))->setTimezone($timezone);
                        $ownerName = $item['owner_name'] ?? ($currentUser->isAdmin() ? '—' : $currentUser->name);
                        ?>
                        <tr style="border-bottom:1px solid rgba(148,163,184,0.16);">
                            <td style="padding:10px;font-weight:600;<?= $index === 0 ? '' : 'color:var(--muted);'; ?>">
                                <?= $index === 0 ? htmlspecialchars($startAt->format('d/m (D)'), ENT_QUOTES, 'UTF-8') : '&nbsp;'; ?>
                            </td>
                            <td style="padding:10px;">
                                <?= htmlspecialchars($startAt->format('H:i') . ' - ' . $endAt->format('H:i'), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:10px;font-weight:600;">
                                <?= htmlspecialchars((string)($item['client_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:10px;color:var(--muted);">
                                <?= htmlspecialchars((string)$ownerName, ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:10px;">
                                <?= htmlspecialchars((string)($item['category'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:10px;">
                                <span style="padding:4px 10px;border-radius:999px;background:rgba(59,130,246,0.16);color:#93c5fd;font-size:0.8rem;">
                                    <?= htmlspecialchars((string)($item['status'] ?? 'scheduled'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <?php if ($canManageAgenda): ?>
                                <td style="text-align:right;padding:10px;white-space:nowrap;">
                                    <?php
                                    $actionData = [
                                        'id' => (int)($item['id'] ?? 0),
                                        'owner_user_id' => (int)($item['owner_user_id'] ?? 0),
                                        'title' => (string)($item['title'] ?? ''),
                                        'client_name' => (string)($item['client_name'] ?? ''),
                                        'client_document' => (string)($item['client_document'] ?? ''),
                                        'category' => (string)($item['category'] ?? ''),
                                        'status' => (string)($item['status'] ?? 'scheduled'),
                                        'starts_at' => (int)($item['starts_at'] ?? 0),
                                        'ends_at' => (int)($item['ends_at'] ?? 0),
                                        'allow_conflicts' => !empty($item['allow_conflicts']),
                                        'description' => (string)($item['description'] ?? ''),
                                        'location' => (string)($item['location'] ?? ''),
                                        'channel' => (string)($item['channel'] ?? ''),
                                    ];
                                    ?>
                                    <button type="button"
                                        data-agenda-edit='<?= htmlspecialchars(json_encode($actionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'
                                        style="border:none;background:transparent;color:var(--accent);cursor:pointer;padding:6px 8px;border-radius:6px;">
                                        Editar
                                    </button>
                                    <button type="button"
                                        data-agenda-delete="<?= (int)($item['id'] ?? 0); ?>"
                                        style="border:none;background:transparent;color:#f87171;cursor:pointer;padding:6px 8px;border-radius:6px;">
                                        Excluir
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="margin-bottom:24px;">
    <details style="margin:0;" data-schedule-config>
        <summary style="cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div>
                <h2 style="margin:0;">Configuração de disponibilidade</h2>
                <p style="margin:4px 0 0;color:var(--muted);font-size:0.9rem;">Defina início, almoço, término e pausas por dia. Slots mínimos de 20 minutos.</p>
            </div>
            <span style="font-size:0.85rem;color:var(--muted);">Clique para expandir</span>
        </summary>
        <form method="post" action="<?= url('agenda/config'); ?>" style="display:grid;gap:12px;margin-top:18px;">
        <?= csrf_field(); ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;min-width:840px;border-collapse:collapse;">
                <thead>
                    <tr style="background:rgba(15,23,42,0.65);">
                        <th style="padding:10px;text-align:left;">Dia</th>
                        <th style="padding:10px;text-align:left;">Início</th>
                        <th style="padding:10px;text-align:left;">Almoço (início)</th>
                        <th style="padding:10px;text-align:left;">Almoço (fim)</th>
                        <th style="padding:10px;text-align:left;">Saída</th>
                        <th style="padding:10px;text-align:left;">Duração slot</th>
                        <th style="padding:10px;text-align:left;">Pausas / off-line<br><small style="color:var(--muted);">Formato HH:MM-HH:MM, 1 por linha</small></th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($day = 1; $day <= 6; $day++):
                        $row = $scheduleMatrix[$day] ?? null;
                        $isClosed = isset($row['is_closed']) ? ((int)$row['is_closed'] === 1) : false;
                        ?>
                        <tr style="border-bottom:1px solid rgba(148,163,184,0.16);" data-day-row data-day-closed="<?= $isClosed ? '1' : '0'; ?>">
                            <td style="padding:10px;font-weight:600;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                                    <span><?= htmlspecialchars($dayNames[$day], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <label class="agenda-switch">
                                        <input type="checkbox" name="config[<?= $day; ?>][is_closed]" value="1" <?= $isClosed ? 'checked' : ''; ?> data-day-toggle>
                                        <span class="agenda-switch-status" data-switch-status><?= $isClosed ? 'Fechado' : 'Aberto'; ?></span>
                                    </label>
                                </div>
                            </td>
                            <td style="padding:6px;">
                                <input type="time" name="config[<?= $day; ?>][start]" value="<?= htmlspecialchars($row['work_start_minutes'] ?? null ? sprintf('%02d:%02d', intdiv((int)$row['work_start_minutes'], 60), ((int)$row['work_start_minutes']) % 60) : '08:00', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.55);color:var(--text);">
                            </td>
                            <td style="padding:6px;">
                                <input type="time" name="config[<?= $day; ?>][lunch_start]" value="<?= htmlspecialchars($row['lunch_start_minutes'] ?? null ? sprintf('%02d:%02d', intdiv((int)$row['lunch_start_minutes'], 60), ((int)$row['lunch_start_minutes']) % 60) : '12:00', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.55);color:var(--text);">
                            </td>
                            <td style="padding:6px;">
                                <input type="time" name="config[<?= $day; ?>][lunch_end]" value="<?= htmlspecialchars($row['lunch_end_minutes'] ?? null ? sprintf('%02d:%02d', intdiv((int)$row['lunch_end_minutes'], 60), ((int)$row['lunch_end_minutes']) % 60) : '13:00', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.55);color:var(--text);">
                            </td>
                            <td style="padding:6px;">
                                <input type="time" name="config[<?= $day; ?>][end]" value="<?= htmlspecialchars($row['work_end_minutes'] ?? null ? sprintf('%02d:%02d', intdiv((int)$row['work_end_minutes'], 60), ((int)$row['work_end_minutes']) % 60) : '18:00', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.55);color:var(--text);">
                            </td>
                            <td style="padding:6px;">
                                <input type="number" min="20" step="5" name="config[<?= $day; ?>][slot]" value="<?= htmlspecialchars((string)($row['slot_duration_minutes'] ?? 20), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.55);color:var(--text);">
                            </td>
                            <td style="padding:6px;">
                                <textarea name="config[<?= $day; ?>][offline]" rows="2" placeholder="14:40-15:00" style="width:100%;padding:8px;border-radius:8px;border:1px solid var(--border);background:rgba(15,23,42,0.55);color:var(--text);resize:vertical;"><?= htmlspecialchars(formatOfflineBlocks($row['offline_blocks'] ?? null), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;">
            <button class="primary" type="submit" style="padding:12px 24px;">Salvar configuração</button>
        </div>
        </form>
        <script>
            (function () {
                var configRoot = document.querySelector('[data-schedule-config]');
                if (!configRoot) {
                    return;
                }

                var toggles = configRoot.querySelectorAll('[data-day-toggle]');
                toggles.forEach(function (toggle) {
                    syncToggle(toggle);
                    toggle.addEventListener('change', function () {
                        syncToggle(toggle);
                    });
                });

                function syncToggle(toggle) {
                    var row = toggle.closest('[data-day-row]');
                    var status = toggle.parentElement.querySelector('[data-switch-status]');
                    var closed = toggle.checked;
                    if (row) {
                        row.setAttribute('data-day-closed', closed ? '1' : '0');
                    }
                    if (status) {
                        status.textContent = closed ? 'Fechado' : 'Aberto';
                    }
                }
            })();
        </script>
    </details>
</div>

<?php
function formatOfflineBlocks(?string $payload): string
{
    if ($payload === null || trim($payload) === '') {
        return '';
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return '';
    }
    $lines = [];
    foreach ($decoded as $block) {
        $start = isset($block['start']) ? sprintf('%02d:%02d', intdiv((int)$block['start'], 60), ((int)$block['start']) % 60) : null;
        $end = isset($block['end']) ? sprintf('%02d:%02d', intdiv((int)$block['end'], 60), ((int)$block['end']) % 60) : null;
        if ($start !== null && $end !== null) {
            $lines[] = $start . '-' . $end;
        }
    }
    return implode("\n", $lines);
}
?>

<?php if ($canManageAgenda): ?>
    <style>
        .agenda-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.65);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            z-index: 1200;
        }
        .agenda-modal-backdrop[hidden] {
            display: none !important;
        }
        .agenda-modal-panel {
            width: 100%;
            max-width: 560px;
            background: rgba(15, 23, 42, 0.95);
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.6);
            overflow: hidden;
            color: var(--text);
        }
        .agenda-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }
        .agenda-modal-header button {
            border: none;
            background: transparent;
            color: var(--muted);
            font-size: 1.5rem;
            cursor: pointer;
        }
        .agenda-modal-body {
            padding: 20px 22px 0;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .agenda-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .agenda-form-grid label,
        .agenda-modal-body label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 0.9rem;
            color: var(--muted);
        }
        .agenda-modal-body input,
        .agenda-modal-body select,
        .agenda-modal-body textarea {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: rgba(15, 23, 42, 0.55);
            color: var(--text);
            font-size: 1rem;
            width: 100%;
        }
        .agenda-modal-body textarea {
            resize: vertical;
            min-height: 80px;
        }
        .agenda-slot-picker {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            background: rgba(15, 23, 42, 0.4);
            min-height: 120px;
        }
        .agenda-slot-picker p {
            margin: 0;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .agenda-slot-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(82px, 1fr));
            gap: 8px;
            margin-top: 10px;
        }
        .agenda-slot-option {
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 10px;
            padding: 8px 10px;
            background: rgba(15, 23, 42, 0.35);
            color: var(--text);
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .agenda-slot-option:hover {
            border-color: var(--accent);
            color: var(--accent);
        }
        .agenda-slot-option.is-selected {
            background: var(--accent);
            border-color: var(--accent);
            color: #0f172a;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.35);
        }
        .agenda-slot-option[disabled] {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .agenda-modal-footer {
            padding: 18px 22px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .agenda-modal-footer button {
            border: none;
            border-radius: 10px;
            padding: 11px 20px;
            font-weight: 600;
            cursor: pointer;
        }
        .agenda-modal-footer .danger {
            background: rgba(248, 113, 113, 0.15);
            color: #fca5a5;
        }
        .agenda-modal-footer .primary {
            background: var(--accent);
            color: #0f172a;
        }
        .agenda-alert {
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.95rem;
        }
        .agenda-alert[data-variant="error"] {
            background: rgba(248, 113, 113, 0.15);
            color: #fecaca;
            border: 1px solid rgba(248, 113, 113, 0.35);
        }
        .agenda-alert[data-variant="success"] {
            background: rgba(74, 222, 128, 0.15);
            color: #bbf7d0;
            border: 1px solid rgba(74, 222, 128, 0.35);
        }
        .agenda-input-error {
            border-color: rgba(248, 113, 113, 0.75) !important;
            box-shadow: 0 0 0 1px rgba(248, 113, 113, 0.25);
        }
    </style>

    <div class="agenda-modal-backdrop" data-agenda-modal hidden
        data-create-url="<?= htmlspecialchars(url('agenda/appointments'), ENT_QUOTES, 'UTF-8'); ?>"
        data-update-template="<?= htmlspecialchars(url('agenda/appointments/__ID__/update'), ENT_QUOTES, 'UTF-8'); ?>"
        data-delete-template="<?= htmlspecialchars(url('agenda/appointments/__ID__/delete'), ENT_QUOTES, 'UTF-8'); ?>"
        data-default-owner="<?= (int)$defaultOwnerId; ?>"
        data-availability-url="<?= htmlspecialchars(url('agenda/availability'), ENT_QUOTES, 'UTF-8'); ?>"
        data-prefill-client-name="<?= htmlspecialchars($prefillAppointment['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        data-prefill-client-document="<?= htmlspecialchars($prefillAppointment['client_document'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        data-prefill-open="<?= !empty($prefillAppointment['auto_open']) ? '1' : '0'; ?>">
        <div class="agenda-modal-panel" role="dialog" aria-modal="true">
            <div class="agenda-modal-header">
                <h3 style="margin:0;font-size:1.2rem;" data-agenda-modal-title>Novo agendamento</h3>
                <button type="button" data-agenda-close aria-label="Fechar">&times;</button>
            </div>
            <div class="agenda-modal-body">
                <div class="agenda-alert" data-agenda-alert hidden></div>
                <form data-agenda-form>
                    <input type="hidden" name="appointment_id" value="">
                    <?php if ($canSwitchAvp && $avpOptions !== []): ?>
                        <label>
                            Colaborador responsável
                            <select name="owner_user_id">
                                <?php foreach ($avpOptions as $avp): ?>
                                    <option value="<?= (int)$avp['id']; ?>" <?= $defaultOwnerId === (int)$avp['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($avp['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php else: ?>
                        <input type="hidden" name="owner_user_id" value="<?= (int)$defaultOwnerId; ?>">
                        <p style="margin:0;color:var(--muted);font-size:0.9rem;">Agendando para <strong><?= htmlspecialchars($currentUser->name, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
                    <?php endif; ?>
                    <label>
                        Protocolo
                        <input type="text" name="title" placeholder="Número do protocolo" maxlength="120" required>
                    </label>
                    <div class="agenda-form-grid">
                        <label>
                            Data
                            <div class="agenda-date-stepper">
                                <button type="button" class="agenda-step-button" data-appointment-date-step="-1" aria-label="Dia anterior">&minus;</button>
                                <input type="date" name="date" data-appointment-date required>
                                <button type="button" class="agenda-step-button" data-appointment-date-step="1" aria-label="Próximo dia">+</button>
                            </div>
                        </label>
                        <label>
                            Horários disponíveis
                            <input type="hidden" name="start_time" value="">
                            <input type="hidden" name="end_time" value="">
                            <div class="agenda-slot-picker" data-slot-picker>
                                <p data-slot-message>Selecione uma data para ver os horários livres.</p>
                                <div class="agenda-slot-options" data-slot-list></div>
                            </div>
                        </label>
                    </div>
                    <div class="agenda-form-grid">
                        <label>
                            Cliente
                            <input type="text" name="client_name" placeholder="Nome do cliente">
                        </label>
                        <label>
                            Documento (CPF/CNPJ)
                            <input type="text" name="client_document" placeholder="Somente números" maxlength="18">
                        </label>
                    </div>
                    <div class="agenda-form-grid">
                        <label>
                            Categoria (Produto CPF/CNPJ)
                            <input type="text" name="category" placeholder="Informe o produto CPF ou CNPJ">
                        </label>
                        <label>
                            Status
                            <select name="status">
                                <?php foreach ($statusLabels as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label>
                        Descrição / Observações
                        <textarea name="description" placeholder="Detalhes adicionais"></textarea>
                    </label>
                    <label style="flex-direction:row;align-items:center;gap:8px;">
                        <input type="checkbox" name="allow_conflicts" value="1" style="width:auto;">
                        Permitir conflito com outros compromissos
                    </label>
                </form>
            </div>
            <div class="agenda-modal-footer">
                <button type="button" class="danger" data-agenda-delete-selected hidden>Excluir</button>
                <div style="margin-left:auto;display:flex;gap:10px;">
                    <button type="button" data-agenda-close>Cancelar</button>
                    <button type="button" class="primary" data-agenda-submit>Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var modal = document.querySelector('[data-agenda-modal]');
            if (!modal) {
                return;
            }

            var form = modal.querySelector('[data-agenda-form]');
            var alertBox = modal.querySelector('[data-agenda-alert]');
            var submitBtn = modal.querySelector('[data-agenda-submit]');
            var deleteBtn = modal.querySelector('[data-agenda-delete-selected]');
            var titleNode = modal.querySelector('[data-agenda-modal-title]');
            var allowConflictsField = form ? form.querySelector('[name="allow_conflicts"]') : null;
            var defaultOwner = parseInt(modal.getAttribute('data-default-owner') || '0', 10) || 0;
            var createUrl = modal.getAttribute('data-create-url') || '';
            var updateTemplate = modal.getAttribute('data-update-template') || '';
            var deleteTemplate = modal.getAttribute('data-delete-template') || '';
            var availabilityUrl = modal.getAttribute('data-availability-url') || '';
            var ownerField = form ? form.querySelector('[name="owner_user_id"]') : null;
            var dateField = form ? form.querySelector('[data-appointment-date]') : (form ? form.querySelector('[name="date"]') : null);
            var startField = form ? form.querySelector('[name="start_time"]') : null;
            var endField = form ? form.querySelector('[name="end_time"]') : null;
            var slotPicker = form ? form.querySelector('[data-slot-picker]') : null;
            var slotList = slotPicker ? slotPicker.querySelector('[data-slot-list]') : null;
            var slotMessage = slotPicker ? slotPicker.querySelector('[data-slot-message]') : null;
            var slotLoadToken = 0;
            var existingSlotMeta = null;
            var dateStepperButtons = form ? form.querySelectorAll('[data-appointment-date-step]') : [];
            var lastValidDateValue = dateField ? dateField.value : '';
            var prefillClientName = modal.getAttribute('data-prefill-client-name') || '';
            var prefillClientDocument = modal.getAttribute('data-prefill-client-document') || '';
            var shouldPrefillOpen = modal.getAttribute('data-prefill-open') === '1';
            var prefillHandled = false;
            var editingId = null;
            var isSubmitting = false;

            var openButtons = document.querySelectorAll('[data-agenda-open-modal]');
            openButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openModal();
                });
            });

            document.querySelectorAll('[data-agenda-edit]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var raw = btn.getAttribute('data-agenda-edit');
                    var payload = null;
                    try {
                        payload = raw ? JSON.parse(raw) : null;
                    } catch (error) {
                        payload = null;
                    }
                    openModal(payload || undefined);
                });
            });

            document.querySelectorAll('[data-agenda-delete]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = parseInt(btn.getAttribute('data-agenda-delete') || '0', 10);
                    if (!id) {
                        return;
                    }
                    confirmAndDelete(id);
                });
            });

            modal.querySelectorAll('[data-agenda-close]').forEach(function (btn) {
                btn.addEventListener('click', closeModal);
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hasAttribute('hidden')) {
                    closeModal();
                }
            });

            if (ownerField) {
                ownerField.addEventListener('change', function () {
                    existingSlotMeta = null;
                    clearSlotSelection();
                    refreshSlots({ preserveSelection: false });
                });
            }

            if (dateField) {
                dateField.addEventListener('change', function () {
                    enforceBusinessDateSelection();
                    existingSlotMeta = null;
                    clearSlotSelection();
                    refreshSlots({ preserveSelection: false });
                });
            }

            if (dateStepperButtons && dateStepperButtons.length > 0) {
                dateStepperButtons.forEach(function (button) {
                    button.addEventListener('click', function () {
                        var direction = parseInt(button.getAttribute('data-appointment-date-step') || '0', 10);
                        adjustAppointmentDate(direction);
                    });
                });
            }

            if (submitBtn) {
                submitBtn.addEventListener('click', handleSubmit);
            }

            if (deleteBtn) {
                deleteBtn.addEventListener('click', function () {
                    if (!editingId) {
                        return;
                    }
                    confirmAndDelete(editingId);
                });
            }

            function openModal(data) {
                editingId = data && data.id ? parseInt(data.id, 10) : null;
                resetForm(data || null);
                toggleDeleteButton(editingId !== null);
                setAlert(null);
                if (titleNode) {
                    titleNode.textContent = editingId ? 'Editar agendamento' : 'Novo agendamento';
                }
                modal.removeAttribute('hidden');
            }

            function closeModal() {
                if (isSubmitting) {
                    return;
                }
                modal.setAttribute('hidden', 'hidden');
                editingId = null;
            }

            function resetForm(data) {
                if (!form) {
                    return;
                }
                form.reset();
                clearFieldErrors();
                if (!data) {
                    var now = new Date();
                    now.setMinutes(now.getMinutes() + 30 - (now.getMinutes() % 15));
                    setFieldValue('owner_user_id', defaultOwner);
                    setFieldValue('date', formatDate(now));
                    setFieldValue('start_time', '');
                    setFieldValue('end_time', '');
                    setFieldValue('status', 'scheduled');
                    if (allowConflictsField) {
                        allowConflictsField.checked = false;
                    }
                } else {
                    Object.keys(data).forEach(function (key) {
                        var value = data[key];
                        switch (key) {
                            case 'starts_at':
                                setFieldValue('date', formatDateFromTimestamp(value));
                                setFieldValue('start_time', formatTimeFromTimestamp(value));
                                break;
                            case 'ends_at':
                                setFieldValue('end_time', formatTimeFromTimestamp(value));
                                break;
                            case 'allow_conflicts':
                                if (allowConflictsField) {
                                    allowConflictsField.checked = Boolean(value);
                                }
                                break;
                            default:
                                setFieldValue(key, value ?? '');
                                break;
                        }
                    });
                }

                existingSlotMeta = deriveExistingSlotMeta(data || null);
                if (!data) {
                    enforceBusinessDateSelection();
                } else if (dateField) {
                    lastValidDateValue = dateField.value;
                }
                refreshSlots({ preserveSelection: true });
            }

            function setFieldValue(name, value) {
                var field = form.querySelector('[name="' + name + '"]');
                if (!field) {
                    return;
                }
                if (field.type === 'checkbox') {
                    field.checked = Boolean(value);
                    return;
                }
                field.value = value === undefined || value === null ? '' : value;
            }

            function formatDate(date) {
                if (!(date instanceof Date)) {
                    return '';
                }
                var month = String(date.getMonth() + 1).padStart(2, '0');
                var day = String(date.getDate()).padStart(2, '0');
                return date.getFullYear() + '-' + month + '-' + day;
            }

            function formatTime(date) {
                if (!(date instanceof Date)) {
                    return '';
                }
                return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
            }

            function formatDateFromTimestamp(value) {
                var timestamp = parseInt(value, 10);
                if (!timestamp) {
                    return '';
                }
                return formatDate(new Date(timestamp * 1000));
            }

            function formatTimeFromTimestamp(value) {
                var timestamp = parseInt(value, 10);
                if (!timestamp) {
                    return '';
                }
                return formatTime(new Date(timestamp * 1000));
            }

            function setAlert(message, variant) {
                if (!alertBox) {
                    return;
                }
                if (!message) {
                    alertBox.setAttribute('hidden', 'hidden');
                    alertBox.removeAttribute('data-variant');
                    alertBox.textContent = '';
                    return;
                }
                alertBox.textContent = message;
                alertBox.setAttribute('data-variant', variant || 'error');
                alertBox.removeAttribute('hidden');
            }

            function clearFieldErrors() {
                form.querySelectorAll('.agenda-input-error').forEach(function (element) {
                    element.classList.remove('agenda-input-error');
                });
            }

            function markErrors(errors) {
                Object.keys(errors).forEach(function (key) {
                    var fieldNames = [key];
                    if (key === 'starts_at' || key === 'ends_at') {
                        fieldNames = key === 'starts_at' ? ['date', 'start_time'] : ['end_time'];
                    }
                    fieldNames.forEach(function (name) {
                        var field = form.querySelector('[name="' + name + '"]');
                        if (field) {
                            field.classList.add('agenda-input-error');
                        }
                    });
                });
            }

            function adjustAppointmentDate(direction) {
                if (!dateField || !direction) {
                    return;
                }
                var current = parseDateInput(dateField.value) || new Date();
                var next = shiftBusinessDay(current, direction);
                dateField.value = formatDate(next);
                dateField.dispatchEvent(new Event('change'));
            }

            function enforceBusinessDateSelection() {
                if (!dateField) {
                    return;
                }
                var normalized = normalizeBusinessDateValue(dateField.value, lastValidDateValue);
                if (normalized !== dateField.value) {
                    dateField.value = normalized;
                }
                lastValidDateValue = dateField.value;
            }

            function normalizeBusinessDateValue(value, fallback) {
                var parsed = parseDateInput(value);
                if (!parsed) {
                    if (fallback && fallback !== '') {
                        return fallback;
                    }
                    return formatDate(shiftBusinessDay(new Date(), 1));
                }
                if (parsed.getDay() === 0) {
                    parsed = shiftBusinessDay(parsed, 1);
                }
                return formatDate(parsed);
            }

            function parseDateInput(value) {
                if (!value) {
                    return null;
                }
                var parsed = new Date(value + 'T00:00:00');
                return Number.isNaN(parsed.getTime()) ? null : parsed;
            }

            function shiftBusinessDay(baseDate, direction) {
                var step = direction >= 0 ? 1 : -1;
                var candidate = new Date(baseDate.getTime());
                do {
                    candidate.setDate(candidate.getDate() + step);
                } while (candidate.getDay() === 0);
                return candidate;
            }

            async function refreshSlots(options) {
                options = options || {};
                if (!slotPicker || !dateField || !startField || !endField) {
                    return;
                }

                var ownerValue = ownerField ? parseInt(ownerField.value || defaultOwner, 10) : defaultOwner;
                var dateValue = (dateField.value || '').trim();

                if (!availabilityUrl) {
                    return;
                }

                if (!ownerValue || !dateValue) {
                    renderSlotOptions([], false);
                    setSlotMessage('Selecione um colaborador e uma data para ver os horários.');
                    clearSlotSelection();
                    return;
                }

                setSlotMessage('Carregando horários livres...');
                renderSlotOptions([], false);
                var token = ++slotLoadToken;

                try {
                    var url = availabilityUrl + '?avp_user_id=' + encodeURIComponent(ownerValue) + '&date=' + encodeURIComponent(dateValue);
                    var response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    if (token !== slotLoadToken) {
                        return;
                    }
                    if (!response.ok) {
                        throw new Error('Falha ao carregar');
                    }
                    var payload = await response.json().catch(function () {
                        return null;
                    }) || {};
                    var slots = Array.isArray(payload.slots) ? payload.slots : [];
                    if (existingSlotMeta && existingSlotMeta.value) {
                        var alreadyPresent = slots.some(function (slot) {
                            return slot.value === existingSlotMeta.value;
                        });
                        if (!alreadyPresent) {
                            slots.unshift(existingSlotMeta);
                        }
                    }
                    renderSlotOptions(slots, options.preserveSelection !== false);
                } catch (error) {
                    if (token !== slotLoadToken) {
                        return;
                    }
                    setSlotMessage('Não foi possível carregar os horários livres.');
                    renderSlotOptions([], false);
                }
            }

            function renderSlotOptions(slots, preserveSelection) {
                if (!slotList) {
                    return;
                }

                slotList.innerHTML = '';

                if (!Array.isArray(slots) || slots.length === 0) {
                    clearSlotSelection();
                    if ((dateField && dateField.value) && (ownerField && ownerField.value)) {
                        setSlotMessage('Sem horários livres para o período selecionado.');
                    }
                    return;
                }

                setSlotMessage('Selecione um horário disponível.');
                var currentSelection = preserveSelection && startField ? startField.value : '';
                var selectionExists = currentSelection
                    ? slots.some(function (slot) { return slot.value === currentSelection; })
                    : false;
                if (!selectionExists) {
                    currentSelection = '';
                }

                slots.forEach(function (slot) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'agenda-slot-option';
                    button.textContent = slot.label || slot.value;
                    button.dataset.value = slot.value;
                    button.addEventListener('click', function () {
                        applySlot(slot);
                    });
                    slotList.appendChild(button);
                });

                if (!currentSelection) {
                    applySlot(slots[0]);
                    currentSelection = slots[0].value;
                }

                updateSlotSelection(currentSelection);
            }

            function applySlot(slot) {
                if (!slot || !startField || !endField) {
                    return;
                }
                var duration = parseInt(slot.duration, 10);
                if (!duration || duration <= 0) {
                    duration = deriveFallbackDuration();
                }
                var endTime = computeEndTime(slot.value, duration);
                setFieldValue('start_time', slot.value);
                setFieldValue('end_time', endTime);
                updateSlotSelection(slot.value);
            }

            function deriveFallbackDuration() {
                if (existingSlotMeta && existingSlotMeta.duration) {
                    return existingSlotMeta.duration;
                }
                return 30;
            }

            function updateSlotSelection(selectedValue) {
                if (!slotList) {
                    return;
                }
                slotList.querySelectorAll('.agenda-slot-option').forEach(function (button) {
                    if (button.dataset.value === selectedValue) {
                        button.classList.add('is-selected');
                    } else {
                        button.classList.remove('is-selected');
                    }
                });
            }

            function clearSlotSelection() {
                if (startField) {
                    startField.value = '';
                }
                if (endField) {
                    endField.value = '';
                }
                updateSlotSelection('');
            }

            function setSlotMessage(message) {
                if (!slotMessage) {
                    return;
                }
                slotMessage.textContent = message || '';
            }

            function computeEndTime(start, durationMinutes) {
                var base = parseTimeToMinutes(start);
                if (base === null) {
                    return '';
                }
                var total = base + Math.max(0, durationMinutes || 0);
                return minutesToTime(total);
            }

            function parseTimeToMinutes(value) {
                if (typeof value !== 'string') {
                    return null;
                }
                var parts = value.split(':');
                if (parts.length !== 2) {
                    return null;
                }
                var hours = parseInt(parts[0], 10);
                var minutes = parseInt(parts[1], 10);
                if (Number.isNaN(hours) || Number.isNaN(minutes)) {
                    return null;
                }
                return (hours * 60) + minutes;
            }

            function minutesToTime(totalMinutes) {
                var hours = Math.floor(totalMinutes / 60);
                var minutes = totalMinutes % 60;
                return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
            }

            function deriveExistingSlotMeta(data) {
                if (!data || !data.starts_at || !data.ends_at) {
                    return null;
                }
                var startTime = formatTimeFromTimestamp(data.starts_at);
                var endTime = formatTimeFromTimestamp(data.ends_at);
                if (!startTime || !endTime) {
                    return null;
                }
                var duration = diffBetweenTimes(startTime, endTime);
                if (!duration) {
                    return null;
                }
                return {
                    label: startTime + ' (atual)',
                    value: startTime,
                    duration: duration,
                    isLocked: true
                };
            }

            function diffBetweenTimes(startTime, endTime) {
                var start = parseTimeToMinutes(startTime);
                var end = parseTimeToMinutes(endTime);
                if (start === null || end === null || end <= start) {
                    return null;
                }
                return end - start;
            }

            function applyPrefillFromDataset() {
                if (!shouldPrefillOpen || prefillHandled) {
                    return;
                }

                var payload = {};
                if (prefillClientName) {
                    payload.client_name = prefillClientName;
                }
                if (prefillClientDocument) {
                    payload.client_document = prefillClientDocument;
                }

                if (Object.keys(payload).length === 0) {
                    return;
                }

                prefillHandled = true;
                openModal(payload);
                setTimeout(function () {
                    if (!form) {
                        return;
                    }
                    var focusTarget = form.querySelector('[name="client_name"]') || form.querySelector('[name="title"]');
                    if (focusTarget) {
                        focusTarget.focus();
                        if (typeof focusTarget.select === 'function') {
                            focusTarget.select();
                        }
                    }
                }, 300);

                try {
                    var clearedUrl = new URL(window.location.href);
                    ['schedule', 'prefill_client_name', 'prefill_client_document'].forEach(function (param) {
                        clearedUrl.searchParams.delete(param);
                    });
                    window.history.replaceState({}, document.title, clearedUrl.toString());
                } catch (error) {
                    // ignore cleanup errors
                }
            }

            async function handleSubmit() {
                if (isSubmitting) {
                    return;
                }

                clearFieldErrors();
                setAlert(null);

                var payload = collectPayload();
                if (!payload) {
                    setAlert('Preencha todos os campos obrigatórios.');
                    return;
                }

                if (!payload.start_time || !payload.end_time) {
                    setAlert('Escolha um horário disponível antes de salvar.');
                    return;
                }

                var endpoint = editingId ? updateTemplate.replace('__ID__', String(editingId)) : createUrl;
                if (!endpoint) {
                    setAlert('Endpoint não configurado.');
                    return;
                }

                try {
                    setSubmitting(true);
                    var response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });
                    var data = await response.json().catch(function () {
                        return null;
                    });

                    if (!response.ok) {
                        throw new Error('Falha ao salvar');
                    }

                    if (data && data.errors) {
                        markErrors(data.errors);
                        var errorMessage = data.errors.general || data.errors.conflict || Object.values(data.errors)[0] || 'Não foi possível salvar.';
                        setAlert(errorMessage, 'error');
                        return;
                    }

                    window.location.reload();
                } catch (error) {
                    setAlert('Erro ao salvar agendamento. Tente novamente.');
                } finally {
                    setSubmitting(false);
                }
            }

            function collectPayload() {
                if (!form) {
                    return null;
                }
                var formData = new FormData(form);
                var payload = {};
                formData.forEach(function (value, key) {
                    payload[key] = value;
                });
                if (allowConflictsField) {
                    payload.allow_conflicts = allowConflictsField.checked ? 1 : 0;
                }
                return payload;
            }

            function toggleDeleteButton(visible) {
                if (!deleteBtn) {
                    return;
                }
                if (visible) {
                    deleteBtn.removeAttribute('hidden');
                } else {
                    deleteBtn.setAttribute('hidden', 'hidden');
                }
            }

            function setSubmitting(state) {
                isSubmitting = state;
                if (submitBtn) {
                    submitBtn.disabled = state;
                    submitBtn.textContent = state ? 'Salvando...' : 'Salvar';
                }
            }

            async function confirmAndDelete(id) {
                if (!id) {
                    return;
                }
                var confirmed = window.confirm('Deseja realmente excluir este agendamento?');
                if (!confirmed) {
                    return;
                }
                var endpoint = deleteTemplate.replace('__ID__', String(id));
                try {
                    setSubmitting(true);
                    var response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    var data = await response.json().catch(function () {
                        return null;
                    });
                    if (!response.ok || (data && data.errors)) {
                        var message = data && data.errors ? (data.errors.general || Object.values(data.errors)[0]) : null;
                        setAlert(message || 'Não foi possível excluir.', 'error');
                        return;
                    }
                    window.location.reload();
                } catch (error) {
                    setAlert('Erro ao excluir. Tente novamente.');
                } finally {
                    setSubmitting(false);
                }
            }
            applyPrefillFromDataset();
        })();
    </script>
<?php endif; ?>
