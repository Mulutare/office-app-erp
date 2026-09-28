<?php
declare(strict_types=1);
$workspace=$data['workspace'];$entity=$data['entity'];$columns=$workspace['columns'][$entity];$rows=$workspace['lists'][$entity]['rows'];$links=$data['links']??[];
?>
<section class="card table-card">
    <h2><?=e($data['title'])?></h2>
    <?php view('components.document-list-controls',['workspace'=>$workspace,'entity'=>$entity]); ?>
    <div class="table-responsive"><table class="data-table"><thead><tr>
        <?php foreach($columns as $label): ?><th><?=e($label)?></th><?php endforeach; ?>
    </tr></thead><tbody>
        <?php if($rows===[]): ?><tr><td colspan="<?=count($columns)?>" class="empty-state">No matching records.</td></tr><?php endif; ?>
        <?php foreach($rows as $row): ?><tr><?php foreach($columns as $key=>$label): ?>
            <td><?php if(isset($links[$key])): ?><a href="<?=e($links[$key]($row))?>"><?=e($row[$key]??'—')?></a><?php else: ?><?=e($row[$key]??'—')?><?php endif; ?></td>
        <?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
