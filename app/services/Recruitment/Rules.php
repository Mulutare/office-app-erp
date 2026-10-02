<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class Rules
{
    public const STATUSES = ['New','Under Review','Shortlisted','Interview','Offered','Hired','Rejected','Withdrawn'];
    public static function email(string $value): ?string
    {
        $value = trim($value);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) return null;
        // Preserve local-part dots, plus suffixes and case; only DNS domain is case-insensitive.
        [$local,$domain] = explode('@',$value,2);
        return $local.'@'.strtolower($domain);
    }
    public static function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Enter a valid date (YYYY-MM-DD).');
        return $value;
    }
    public static function identity(int $mailbox, string $folder, int $validity, int $uid): string
    {
        return hash('sha256',json_encode([$mailbox,$folder,$validity,$uid],JSON_THROW_ON_ERROR));
    }
    public static function extract(array $message): array
    {
        $sender = (string)($message['sender_email'] ?? '');
        $body = (string)($message['text'] ?? '');
        $forwarded = preg_match('/(?:^|\s)(?:fw:|fwd:)|\b(?:forwarded|on behalf|agency|recruitment agency)\b/i',(string)($message['subject']??'').' '.$body) === 1;
        preg_match('/\b(?:phone|mobile|tel)\s*[:=]\s*(\+?[0-9 ()-]{7,25})/i',$body,$phone);
        return ['name'=>trim((string)($message['sender_name']??'')) ?: 'Identity requires review', 'email'=>$forwarded?'':$sender, 'phone'=>trim($phone[1]??''), 'identity_review'=>true];
    }
    public static function file(string $name, string $bytes, int $limit): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        $ext = strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $types = ['pdf'=>['application/pdf'],'txt'=>['text/plain'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'doc'=>['application/msword','application/x-ole-storage','application/CDFV2'],'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip']];
        $status = strlen($bytes)>$limit?'oversized':((isset($types[$ext]) && in_array($mime,$types[$ext],true))?'accepted':'rejected');
        if (substr($bytes,0,2)==='MZ' || substr($bytes,0,4)==="\x7fELF") $status='rejected';
        if ($ext==='docx' && $status==='accepted') {
            // Validate the ZIP structure and forbid embedded executable/macro payloads.
            $temp = tempnam(sys_get_temp_dir(),'rec-');
            file_put_contents($temp,$bytes);
            $zip = new \ZipArchive();
            if ($zip->open($temp)!==true) $status='rejected';
            else {
                if ($zip->locateName('[Content_Types].xml')===false || $zip->locateName('word/document.xml')===false) $status='rejected';
                $expanded=0;
                for($i=0;$i<$zip->numFiles;$i++) {
                    $entry=$zip->statIndex($i); $expanded+=(int)$entry['size'];
                    if(preg_match('/(?:vbaProject|embeddings\/|\.exe$|\.js$|\.bin$)/i',$entry['name'])) $status='rejected';
                }
                if($expanded>$limit*10) $status='rejected';
                $zip->close();
            }
            unlink($temp);
        }
        return ['detected_type'=>$mime,'validation_status'=>$status,'size_bytes'=>strlen($bytes),'checksum'=>hash('sha256',$bytes)];
    }
}
