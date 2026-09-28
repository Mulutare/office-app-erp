<?php
declare(strict_types=1);
$workspace=$data['workspace'];$entity=$data['entity'];$list=$workspace['calendarLists'][$entity];$path=appBasePath().'/attendance/calendars';
view('components.list-filters',['path'=>$path,'query'=>$list['query']]+$workspace['calendarControls'][$entity]);
view('components.list-download',['path'=>$path,'query'=>$list['query'],'allowed'=>true,'actions'=>['register'=>$entity]]);
view('components.list-pagination',['path'=>$path,'query'=>$list['query'],'pagination'=>$list['pagination']]);
