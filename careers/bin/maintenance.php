<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(404);exit(1);}
try {
    $db=new PDO((string)getenv('CAREERS_DB_DSN'),(string)getenv('CAREERS_DB_USER'),(string)getenv('CAREERS_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach(['careers_nonces','careers_limits'] as $table) { $s=$db->prepare('DELETE FROM '.$table.' WHERE expires < ? LIMIT 10000'); $s->execute([time()]); }
    echo "Expired security metadata cleared; application evidence retained.\n";
} catch(Throwable $e) {fwrite(STDERR,"Careers maintenance failed.\n");exit(1);}
