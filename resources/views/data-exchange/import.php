<?php
declare(strict_types=1);
$schema = $data['schema'];
$preview = is_array($data['preview'] ?? null) ? $data['preview'] : null;
$result = $data['result'] ?? null;
$error = $data['error'] ?? null;
$isHr = in_array($schema->entity, ['employees', 'attendance'], true);
$mode=(string)($preview['mode']??'create');
$canUpdate=\App\Services\DataExchange\MasterImportValidator::supportsUpdate($schema->entity);
$modeControl=static function()use($mode,$canUpdate):void { ?>
    <div class="form-field"><label>Import mode<select name="mode"><option value="create"<?= $mode==='create'?' selected':'' ?>>Create new records only</option>
    <?php if($canUpdate): ?><option value="update"<?= $mode==='update'?' selected':'' ?>>Update existing records by External ID</option><?php endif; ?></select></label>
    <small>Create rejects existing identifiers. Update requires an existing External ID and preserves unmapped master fields. Changing mode requires Test Import before confirmation.</small></div>
<?php };
$showErrors = static function (object $result): void { ?>
    <?php if ($result->errors !== []): ?>
        <div class="table-responsive"><table class="data-table">
            <thead><tr><th>Row</th><th>Column</th><th>Value</th><th>Reason</th></tr></thead>
            <tbody><?php foreach ($result->errors as $item): ?>
                <tr><td><?= e($item['row']) ?></td><td><?= e($item['field']) ?></td><td><?= e($item['value'] ?? '') ?></td><td><?= e($item['message']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
    <?php foreach ($result->warnings as $warning): ?><p class="alert alert-warning"><?= e($warning) ?></p><?php endforeach; ?>
<?php };
$showSummary = static function (object $result): void {
    $metrics = ['Total rows'=>$result->rowsRead, 'Valid rows'=>$result->valid, 'Invalid rows'=>$result->invalidRows,
        'Duplicate rows'=>$result->duplicateRows, 'Warnings'=>count($result->warnings)]; ?>
    <div class="finance-summary-grid"><?php foreach ($metrics as $label=>$value): ?>
        <div><strong><?= e($value) ?></strong><span><?= e($label) ?></span></div>
    <?php endforeach; ?></div>
<?php }; ?>

<header class="page-header">
    <div><p class="eyebrow"><?= e(ucfirst($schema->module)) ?> data exchange</p><h1>Import <?= e($schema->label) ?></h1><p>Upload CSV or Excel XLSX, review the rows, then confirm. Formulas are rejected. Test Import makes no business-data changes.</p></div>
    <div class="filter-actions">
        <a class="btn btn-secondary" href="/office_app/public/data-exchange/<?= e($schema->entity) ?>/template?format=xlsx">Download XLSX template</a>
        <a class="btn btn-secondary" href="/office_app/public/data-exchange/<?= e($schema->entity) ?>/template?format=csv">CSV template</a>
    </div>
</header>
<?php if(!$isHr): ?><section class="card"><p>Every row is validated before the batch is written. Any invalid row prevents the entire import. The company comes from your current workspace.</p>
<?php if($schema->entity==='products'||$schema->entity==='quotations'): ?><p>Selling prices, discounts and taxes remain controlled by approved pricing. Quotation imports create or update drafts; they do not approve, confirm, deliver or post a document.</p><?php endif; ?></section><?php endif; ?>
<?php if ($isHr): ?>
    <section class="card"><p>Create-only import: existing employee identifiers and employee/date attendance pairs are rejected. Any invalid row prevents the entire import. Company is taken from your current workspace.</p>
        <?php if ($schema->entity === 'employees'): ?>
            <p>Use an active Department Code, an existing manager's Employee Number and an optional company Username. Employment Type: full_time, part_time, contract, temporary or intern. Status: active, on_leave, suspended or terminated. Dates: YYYY-MM-DD. Branches and structured positions are assigned through the existing position workflow after creation.</p>
        <?php else: ?>
            <p>Use Employee Number, YYYY-MM-DD dates and HH:MM times. Status: present, late, absent, remote, on_leave or holiday. Existing clock records are never replaced. Attendance dates must follow the existing one-year/tomorrow policy.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php if (is_string($error)): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($result !== null): ?>
    <section class="card"><h2>Import result</h2><?php $showSummary($result); ?>
        <p>Created: <?= e($result->created) ?> · Updated: <?= e($result->updated) ?> · Skipped: <?= e($result->skipped) ?> · Errors: <?= e(count($result->errors)) ?></p>
        <?php $showErrors($result); ?>
    </section>
<?php endif; ?>
<?php if ($preview === null): ?>
    <section class="card"><form method="post" enctype="multipart/form-data" action="/office_app/public/data-exchange/<?= e($schema->entity) ?>/preview">
        <?= csrfField() ?><?php $modeControl(); ?>
        <div class="form-field"><label for="exchange_file">Spreadsheet file</label><input id="exchange_file" name="file" type="file" accept=".xlsx,.csv" required><small>Maximum 10 MB, 10,000 rows and 100 columns.</small></div>
        <button class="btn btn-primary">Upload and preview</button>
    </form></section>
<?php else: ?>
    <section class="card"><h2>Validation and column mapping</h2><?php $showSummary($preview['result']); ?>
        <form method="post" action="/office_app/public/data-exchange/<?= e($schema->entity) ?>/import">
            <?= csrfField() ?><?php $modeControl(); ?><input type="hidden" name="upload_token" value="<?= e($preview['token']) ?>">
            <div class="table-responsive"><table class="data-table">
                <thead><tr><th>Spreadsheet column</th><th>OfficeApp field</th></tr></thead>
                <tbody><?php foreach ($preview['headers'] as $index=>$header): ?>
                    <tr><td><?= e($header) ?></td><td><select name="mapping[<?= e($index) ?>]">
                        <option value="">Do not import</option>
                        <?php foreach ($schema->fields as $field): ?><option value="<?= e($field->key) ?>" <?= ($preview['mapping'][$index] ?? null) === $field->key ? 'selected' : '' ?>><?= e($field->label) ?><?= $field->required ? ' *' : '' ?></option><?php endforeach; ?>
                    </select></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <div class="filter-actions">
                <button class="btn btn-secondary" name="action" value="test">Test Import</button>
                <?php if ($schema->canImport): ?><button class="btn btn-primary" name="action" value="import" <?= count($preview['result']->errors) > 0 ? 'disabled' : '' ?>>Confirm import</button><?php endif; ?>
                <a class="btn btn-secondary" href="/office_app/public/data-exchange/<?= e($schema->entity) ?>/import">Upload corrected file</a>
            </div>
        </form>
    </section>
    <section class="card table-card"><h2>Preview (first 20 rows)</h2>
        <div class="table-responsive"><table class="data-table">
            <thead><tr><th>Row</th><?php foreach ($preview['headers'] as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($preview['rows'] as $offset=>$row): ?><tr><td><?= e($offset+2) ?></td><?php foreach ($row as $value): ?><td><?= e($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
        <?php $showErrors($preview['result']); ?>
    </section>
<?php endif; ?>
