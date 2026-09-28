<?php
declare(strict_types=1);
$query = $data['query'];
$pagination = $data['pagination'];
$path = $data['path'];
?>
<nav class="pagination smart-list-pagination" aria-label="Record pagination">
    <span class="pagination-status">Showing <?= e($pagination['from']) ?>&ndash;<?= e($pagination['to']) ?> of <?= e($pagination['total']) ?> records</span>
    <?php if ($pagination['page'] > 1): ?>
        <a class="pagination-link" href="<?= e($query->url($path, ['page' => $pagination['page'] - 1])) ?>">Previous</a>
    <?php endif; ?>
    <?php
    $pages = array_unique(array_merge([1, $pagination['lastPage']], range(
        max(1, $pagination['page'] - 2), min($pagination['lastPage'], $pagination['page'] + 2)
    )));
    sort($pages);
    $previousPage = 0;
    foreach ($pages as $pageNumber): ?>
        <?php if ($previousPage && $pageNumber > $previousPage + 1): ?><span aria-hidden="true">&hellip;</span><?php endif; ?>
        <?php if ($pageNumber === $pagination['page']): ?>
            <span class="pagination-link" aria-current="page"><?= e($pageNumber) ?></span>
        <?php else: ?>
            <a class="pagination-link" aria-label="Page <?= e($pageNumber) ?>" href="<?= e($query->url($path, ['page' => $pageNumber])) ?>"><?= e($pageNumber) ?></a>
        <?php endif; $previousPage = $pageNumber; ?>
    <?php endforeach; ?>
    <?php if ($pagination['page'] < $pagination['lastPage']): ?>
        <a class="pagination-link" href="<?= e($query->url($path, ['page' => $pagination['page'] + 1])) ?>">Next</a>
    <?php endif; ?>
</nav>
