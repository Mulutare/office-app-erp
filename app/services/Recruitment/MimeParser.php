<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

/** Small conservative RFC822 reader. Unsupported/malformed content stays preserved as raw mail. */
final class MimeParser
{
    public function parse(string $raw): array
    {
        [$headers,$body]=$this->split($raw);
        $from=$this->decode($headers['from']??'');
        preg_match('/(?:^|<)([^<>\s]+@[^<>\s]+)>?$/',$from,$address);
        $text=''; $attachments=[];
        $this->part($headers,$body,'1',0,$text,$attachments);
        $date=strtotime($headers['date']??'');
        return ['sender'=>$from,'sender_email'=>$address[1]??'','sender_name'=>trim(preg_replace('/<[^>]+>/', '',$from),' \"'), 'recipients'=>$this->decode($headers['to']??'').' '.$this->decode($headers['cc']??''), 'subject'=>$this->decode($headers['subject']??''), 'message_id'=>$headers['message-id']??null, 'received_at'=>$date?gmdate('Y-m-d H:i:s',$date):gmdate('Y-m-d H:i:s'), 'text'=>$text,'attachments'=>$attachments];
    }
    private function split(string $raw): array
    {
        $parts=preg_split('/\r?\n\r?\n/',$raw,2);
        if(count($parts)!==2) throw new \RuntimeException('mime_invalid');
        $headers=[];
        foreach(explode("\n",preg_replace('/\r?\n[ \t]+/',' ',$parts[0])) as $line) {
            if(!str_contains($line,':')) continue;
            [$key,$value]=explode(':',$line,2); $headers[strtolower(trim($key))]=trim($value);
        }
        return [$headers,$parts[1]];
    }
    private function parameter(string $header,string $key): string
    {
        preg_match('/(?:^|;)\s*'.preg_quote($key,'/').'\s*=\s*(?:"([^"]*)"|([^;\s]+))/i',$header,$m);
        return ($m[1]??'')!==''?$m[1]:($m[2]??'');
    }
    private function decode(string $value): string
    {
        $decoded=iconv_mime_decode($value,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8');
        return $decoded===false?$value:$decoded;
    }
    private function part(array $headers,string $body,string $key,int $depth,string &$text,array &$attachments): void
    {
        if($depth>15 || count($attachments)>100) throw new \RuntimeException('mime_limits');
        $type=$headers['content-type']??'text/plain';
        if(str_starts_with(strtolower($type),'multipart/')) {
            $boundary=$this->parameter($type,'boundary');
            if($boundary==='' || !str_contains($body,'--'.$boundary.'--')) throw new \RuntimeException('mime_boundary');
            $parts=preg_split('/(?:^|\r?\n)--'.preg_quote($boundary,'/').'(?:--)?[ \t]*(?:\r?\n|$)/',$body);
            array_shift($parts); array_pop($parts);
            foreach($parts as $i=>$raw) { [$h,$b]=$this->split($raw); $this->part($h,$b,$key.'.'.($i+1),$depth+1,$text,$attachments); }
            return;
        }
        $encoding=strtolower($headers['content-transfer-encoding']??'');
        if($encoding==='base64') { $body=base64_decode(preg_replace('/\s/','',$body),true); if($body===false) throw new \RuntimeException('mime_base64'); }
        elseif($encoding==='quoted-printable') $body=quoted_printable_decode($body);
        $disposition=$headers['content-disposition']??'';
        $filename=$this->parameter($disposition,'filename') ?: $this->parameter($type,'name');
        if($filename!=='' || stripos($disposition,'attachment')!==false || (!str_starts_with(strtolower($type),'text/') && !str_starts_with(strtolower($type),'message/'))) {
            $filename=$filename ?: 'attachment-'.$key.'.bin';
            $attachments[]=['part_key'=>$key,'name'=>$this->decode($filename),'bytes'=>$body]; return;
        }
        $charset=$this->parameter($type,'charset') ?: 'UTF-8';
        $converted=@iconv($charset,'UTF-8//IGNORE',$body); $body=$converted===false?$body:$converted;
        // HTML is converted to text and escaped in the view. No remote resource is rendered.
        if(str_starts_with(strtolower($type),'text/html')) $body=html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is','',$body)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(str_starts_with(strtolower($type),'message/')) throw new \RuntimeException('mime_nested_message_review');
        $text.=$body."\n";
    }
}
