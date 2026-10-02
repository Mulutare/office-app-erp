<?php
declare(strict_types=1);
namespace App\Services\Recruitment;
use App\Services\PowerBiSecretCipher;

final class ImapProvider implements MailProvider
{
    private mixed $connection=null;
    private string $path='';
    public function connect(array $mailbox): void
    {
        if(!function_exists('imap_open')) throw new \RuntimeException('imap_extension_missing');
        if(!preg_match('/^[a-z0-9][a-z0-9.-]*$/i',$mailbox['host']) || preg_match('/[{}\r\n]/',$mailbox['folder'])) throw new \RuntimeException('mailbox_configuration_invalid');
        $allowed=array_filter(array_map('trim',explode(',',getenv('RECRUITMENT_IMAP_ALLOWED_HOSTS')?:'')));
        if(!in_array(strtolower($mailbox['host']),array_map('strtolower',$allowed),true)) throw new \RuntimeException('imap_host_not_allowlisted');
        if(!in_array((int)$mailbox['port'],[993,143],true)) throw new \RuntimeException('imap_port_invalid');
        imap_timeout(IMAP_OPENTIMEOUT,15); imap_timeout(IMAP_READTIMEOUT,30);
        $transport=(int)$mailbox['port']===993?'ssl':'tls';
        $this->path='{'.$mailbox['host'].':'.$mailbox['port'].'/imap/'.$transport.'/validate-cert/norsh}'.imap_utf7_encode($mailbox['folder']);
        // No warnings reach ERP logs: native provider errors can contain usernames.
        $this->connection=@imap_open($this->path,$mailbox['username'],(new PowerBiSecretCipher())->decrypt($mailbox['secret']),OP_READONLY,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
        $this->drain();
        if(!$this->connection) throw new \RuntimeException('imap_connect_failed');
    }
    public function inventory(string $since): array
    {
        Rules::date($since);
        $status=@imap_status($this->connection,$this->path,SA_UIDVALIDITY);
        if(!$status || empty($status->uidvalidity)) { $this->drain(); throw new \RuntimeException('imap_status_failed'); }
        $uids=@imap_search($this->connection,'SINCE "'.date('d-M-Y',strtotime($since)).'"',SE_UID);
        // No matches and a failed SEARCH both return false; native errors distinguish them.
        $errors=imap_errors(); imap_alerts();
        if($errors) throw new \RuntimeException('imap_search_failed');
        $uids=$uids?:[]; sort($uids,SORT_NUMERIC);
        return ['validity'=>(int)$status->uidvalidity,'uids'=>$uids];
    }
    public function raw(int $uid): string
    {
        $header=@imap_fetchheader($this->connection,$uid,FT_UID);
        $body=@imap_body($this->connection,$uid,FT_UID|FT_PEEK);
        $this->drain();
        if(!is_string($header)||!is_string($body)) throw new \RuntimeException('imap_fetch_failed');
        return rtrim($header,"\r\n")."\r\n\r\n".$body;
    }
    public function parse(string $raw): array { return (new MimeParser())->parse($raw); }
    public function arrival(int $uid): string
    {
        $overview=@imap_fetch_overview($this->connection,(string)$uid,FT_UID);
        $this->drain(); $timestamp=(int)($overview[0]->udate??0);
        if(!$timestamp) throw new \RuntimeException('imap_arrival_date_failed');
        return gmdate('Y-m-d H:i:s',$timestamp);
    }
    private function drain(): void { imap_errors(); imap_alerts(); }
    public function close(): void { if($this->connection) { @imap_close($this->connection); $this->connection=null; $this->drain(); } }
    public function __destruct() { $this->close(); }
}
