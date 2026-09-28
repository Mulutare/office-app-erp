<?php declare(strict_types=1); ?>
<?php if (!empty($data['allowed'])): ?>
<div class="filter-actions" aria-label="Export matching records">
    <?php foreach (['xlsx'=>'Excel','csv'=>'CSV'] as $format=>$label): ?>
        <a class="btn btn-secondary" href="<?= e($data['query']->url($data['path'], [], ['download'=>$format]+($data['actions']??[]))) ?>">Export filtered (<?= e($label) ?>)</a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
