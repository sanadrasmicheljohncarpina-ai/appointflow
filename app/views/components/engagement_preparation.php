<?php
/**
 * Preparation checklist.
 * Expects: $prepTasks, $prepSummary (done/total/state), $nowLocal, $tz, $wfAction, $wfFields (callable).
 */
$prepStateLabels = ['not_started' => 'Not started', 'in_progress' => 'In progress', 'ready' => 'Ready'];
$prepPercent = $prepSummary['total'] > 0 ? (int)round($prepSummary['done'] / $prepSummary['total'] * 100) : 0;
?>
<section class="content-card" id="preparation">
    <div class="section-head">
        <div><p class="eyebrow">PREPARATION</p><h2>Before the meeting</h2></div>
        <span class="prep-state prep-<?= h($prepSummary['state']) ?>"><?= h($prepStateLabels[$prepSummary['state']] ?? '') ?></span>
    </div>
    <div class="progress-row">
        <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int)$prepSummary['total'] ?>" aria-valuenow="<?= (int)$prepSummary['done'] ?>"><span style="width:<?= $prepPercent ?>%"></span></div>
        <span class="muted"><?= (int)$prepSummary['done'] ?> / <?= (int)$prepSummary['total'] ?> done</span>
    </div>

    <?php if (!$prepTasks): ?>
        <div class="empty-state"><strong>No preparation tasks.</strong><p>Add the first task below.</p></div>
    <?php else: ?>
        <ul class="prep-list">
            <?php foreach ($prepTasks as $task): ?>
                <?php
                $isDone = $task['status'] === 'completed';
                $assignee = trim((string)$task['assigned_first'] . ' ' . (string)$task['assigned_last']);
                $isOverdue = !$isDone && $task['due_at'] !== null && (string)$task['due_at'] < $nowLocal;
                ?>
                <li class="prep-item<?= $isDone ? ' is-done' : '' ?>">
                    <form method="post" action="<?= h($wfAction) ?>" class="inline-form">
                        <?= $wfFields($isDone ? 'prep_reopen' : 'prep_complete') ?>
                        <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                        <button class="prep-check" type="submit" aria-label="<?= $isDone ? 'Reopen task' : 'Mark task done' ?>: <?= h($task['title']) ?>"><?= $isDone ? '&#9745;' : '&#9744;' ?></button>
                    </form>
                    <div class="prep-body">
                        <strong><?= h($task['title']) ?></strong>
                        <span class="muted">
                            <?= $assignee !== '' ? 'Assigned: ' . h($assignee) : 'Unassigned' ?>
                            <?php if ($task['due_at'] !== null): ?> · Due <?= h((new DateTimeImmutable((string)$task['due_at'], $tz))->format('M j')) ?><?php endif; ?>
                            <?php if ($isOverdue): ?> · <span class="overdue-flag">Overdue</span><?php endif; ?>
                            <?php if ($isDone && $task['completed_at'] !== null): ?> · Done <?= h((new DateTimeImmutable((string)$task['completed_at']))->format('M j')) ?><?php endif; ?>
                        </span>
                    </div>
                    <form method="post" action="<?= h($wfAction) ?>" class="inline-form" onsubmit="return confirm('Remove this task?')">
                        <?= $wfFields('prep_remove') ?>
                        <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                        <button class="link-button" type="submit">Remove</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="<?= h($wfAction) ?>" class="prep-add">
        <?= $wfFields('prep_add') ?>
        <input type="text" name="title" maxlength="200" placeholder="Add a preparation task" aria-label="Task title" required>
        <input type="date" name="due_date" aria-label="Due date (optional)">
        <button class="button secondary" type="submit">Add task</button>
    </form>
</section>
