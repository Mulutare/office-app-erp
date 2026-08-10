<?php
declare(strict_types=1);
$schema=$data['schema'];
?>
<header class="page-header">
    <div><p class="eyebrow"><?=e(ucfirst($schema->module))?> data exchange</p><h1>Export <?=e($schema->label)?></h1><p>Select, remove and reorder fields before downloading a genuine XLSX or CSV file.</p></div>
</header>
<section class="card">
<form id="export-form" method="get" action="/office_app/public/data-exchange/<?=e($schema->entity)?>/export">
    <div class="form-grid">
        <div class="form-field"><label for="export-format">File type</label><select id="export-format" name="format"><option value="xlsx">Excel XLSX</option><option value="csv">CSV</option></select></div>
        <?php if($schema->canImport):?><label class="form-field"><span>Import-compatible export</span><span><input id="import-compatible" type="checkbox" name="import_compatible" value="1"> Include External ID automatically for safe updates</span></label><?php endif;?>
    </div>
    <div class="form-grid">
        <div class="form-field"><label for="available-fields">Available fields</label><select id="available-fields" multiple size="12"><?php foreach($schema->fields as $field):?><option value="<?=e($field->key)?>"><?=e($field->label)?></option><?php endforeach;?></select><button class="btn btn-secondary" type="button" id="add-field">Add →</button></div>
        <div class="form-field"><label for="selected-fields">Selected fields</label><select id="selected-fields" multiple size="12"><?php foreach($schema->fields as $field):?><option value="<?=e($field->key)?>"><?=e($field->label)?></option><?php endforeach;?></select><div class="filter-actions"><button class="btn btn-secondary" type="button" id="remove-field">← Remove</button><button class="btn btn-secondary" type="button" id="move-up">Move up</button><button class="btn btn-secondary" type="button" id="move-down">Move down</button></div></div>
    </div>
    <input id="export-fields" type="hidden" name="fields" value="<?=e(implode(',',array_map(static fn($field)=>$field->key,$schema->fields)))?>">
    <button class="btn btn-primary" type="submit">Download Export</button>
</form>
</section>
<script>
(()=>{const available=document.getElementById('available-fields'),selected=document.getElementById('selected-fields'),hidden=document.getElementById('export-fields'),compatible=document.getElementById('import-compatible');
const sync=()=>{hidden.value=[...selected.options].map(o=>o.value).join(',');};
const move=(from,to)=>{[...from.selectedOptions].forEach(o=>to.appendChild(o));sync();};
document.getElementById('add-field').addEventListener('click',()=>move(available,selected));document.getElementById('remove-field').addEventListener('click',()=>{[...selected.selectedOptions].forEach(o=>{if(!(compatible?.checked&&o.value==='external_id'))available.appendChild(o)});sync();});
const reorder=direction=>{const option=selected.selectedOptions[0];if(!option)return;const sibling=direction<0?option.previousElementSibling:option.nextElementSibling;if(!sibling)return;direction<0?selected.insertBefore(option,sibling):selected.insertBefore(sibling,option);sync();};
document.getElementById('move-up').addEventListener('click',()=>reorder(-1));document.getElementById('move-down').addEventListener('click',()=>reorder(1));compatible?.addEventListener('change',()=>{let external=[...selected.options].find(o=>o.value==='external_id')||[...available.options].find(o=>o.value==='external_id');if(compatible.checked&&external)selected.insertBefore(external,selected.firstChild);sync();});document.getElementById('export-form').addEventListener('submit',sync);})();
</script>
