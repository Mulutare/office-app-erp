<?php
declare(strict_types=1);
$query = $data['query'];
$path = $data['path'];
?>
<form method="get" action="<?= e($path) ?>" class="smart-list-filters">
    <?php
    $preserve = static function (array $values, string $parent = '') use (&$preserve): void {
        foreach ($values as $name => $value) {
            $name = $parent === '' ? (string)$name : $parent . '[' . $name . ']';
            if (is_array($value)) $preserve($value, $name);
            elseif (is_scalar($value)) echo '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
        }
    };
    $preserve($query->context());
    ?>
    <input type="hidden" name="<?= e($query->field('page')) ?>" value="1">
    <?php foreach (($data['hidden'] ?? []) as $key => $value): ?>
        <input type="hidden" name="<?= e($query->field($key)) ?>" value="<?= e($value) ?>">
    <?php endforeach; ?>
    <div class="form-field smart-list-search"><label for="<?= e($query->prefix) ?>list-q">Search</label><input id="<?= e($query->prefix) ?>list-q" type="search" name="<?= e($query->field('q')) ?>" value="<?= e($query->q) ?>" maxlength="100" placeholder="Search business identifiers and names"></div>
    <?php foreach (($data['filters'] ?? []) as $key => $filter): ?>
        <div class="form-field"><label for="<?= e($query->prefix) ?>list-<?= e($key) ?>"><?= e($filter['label']) ?></label>
        <?php if (in_array($filter['type'] ?? '', ['date', 'month', 'text'], true)): ?>
            <input id="<?= e($query->prefix) ?>list-<?= e($key) ?>" type="<?= e($filter['type']) ?>" name="<?= e($query->field($key)) ?>" value="<?= e($query->filters[$key] ?? '') ?>">
        <?php else: ?>
            <select id="<?= e($query->prefix) ?>list-<?= e($key) ?>" name="<?= e($query->field($key)) ?>"><?php if ($filter['allowAll'] ?? true): ?><option value="">All</option><?php endif; ?>
                <?php if (empty($filter['options'])): ?><option value="" disabled>No available values</option><?php endif; ?>
                <?php foreach ($filter['options'] as $value => $label): ?><option value="<?= e($value) ?>" <?= (string)($query->filters[$key] ?? '') === (string)$value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?></div>
    <?php endforeach; ?>
    <div class="form-field"><label for="<?= e($query->prefix) ?>list-sort">Sort by</label><select id="<?= e($query->prefix) ?>list-sort" name="<?= e($query->field('sort')) ?>"><?php foreach ($data['sorts'] as $key => $label): ?><option value="<?= e($key) ?>" <?= $query->sort === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="form-field"><label for="<?= e($query->prefix) ?>list-direction">Direction</label><select id="<?= e($query->prefix) ?>list-direction" name="<?= e($query->field('direction')) ?>"><option value="asc" <?= $query->direction === 'asc' ? 'selected' : '' ?>>Ascending ↑</option><option value="desc" <?= $query->direction === 'desc' ? 'selected' : '' ?>>Descending ↓</option></select></div>
    <div class="form-field"><label for="<?= e($query->prefix) ?>list-size">Per page</label><select id="<?= e($query->prefix) ?>list-size" name="<?= e($query->field('per_page')) ?>"><?php foreach ([25,50,100] as $size): ?><option <?= $query->perPage === $size ? 'selected' : '' ?>><?= e($size) ?></option><?php endforeach; ?></select></div>
    <div class="filter-actions"><button class="btn btn-primary">Apply</button><a class="btn btn-secondary" href="<?= e($query->resetUrl($path,$data['hidden']??[])) ?>">Clear filters</a></div>
</form>
