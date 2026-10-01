function Get-RehearsalDumpAudit([string]$Path) {
    $accounts=@{};$header=@();$objects=@();$pending=$null;$lineNumber=0
    $stream=[IO.File]::OpenRead($Path);$gzip=New-Object IO.Compression.GZipStream($stream,[IO.Compression.CompressionMode]::Decompress);$reader=New-Object IO.StreamReader($gzip)
    # Parse quoted account tokens only; reject every unmatched ownership clause.
    $token='(`(?:``|[^`])*`|''(?:''''|[^''])*''|"(?:""|[^"])*")'
    $pattern='DEFINER\s*=\s*'+$token+'\s*@\s*'+$token
    try {
        while($null-ne($line=$reader.ReadLine())) {
            $lineNumber++
            if($line-match'^-- (MySQL dump|MariaDB dump|Server version|Host:)'){$header+=$line.ToString()}
            # Data rows can contain descriptions such as "Create user"; they are not DDL.
            if($line-match'^INSERT INTO '){continue}
            if($line-match'(?i)\b(?:CREATE\s+USER|GRANT\s|CREATE\s+DATABASE|USE\s+`)|\bmysql\s*[.`]') {throw "Unreviewed account/system/schema SQL at dump line $lineNumber"}
            if($line-match'(?i)\b(?:PROCEDURE|FUNCTION|EVENT)\s+`'){throw "Routine/event requires separate privilege review at dump line $lineNumber"}
            if($line-match'DEFINER\s*=') {
                $matchesFound=[regex]::Matches($line,$pattern)
                if($matchesFound.Count-ne1-or([regex]::Matches($line,'DEFINER\s*=')).Count-ne1){throw "Unparseable definer at line $lineNumber"}
                $m=$matchesFound[0];$values=@()
                foreach($i in 1,2){$v=$m.Groups[$i].Value;$q=$v.Substring(0,1);$v=$v.Substring(1,$v.Length-2).Replace(($q+$q),$q);if($v-match'[\x00-\x1f\\]' -or $v.Length-eq0){throw 'Unsupported account identifier'};$values+=$v}
                if($values[0].Length-gt32-or$values[1].Length-gt255){throw 'Account identifier exceeds MySQL limits'}
                $key=$values[0]+'@'+$values[1]
                if(-not$accounts.ContainsKey($key)){$accounts[$key]=[ordered]@{User=$values[0];Host=$values[1];Count=0;Types=@{VIEW=0;TRIGGER=0;PROCEDURE=0;FUNCTION=0;EVENT=0;other=0}}}
                $accounts[$key].Count++;$pending=$key
            }
            if($null-ne$pending-and$line-match'\b(VIEW|TRIGGER)\s+`([^`]+)`'){$type=$Matches[1];$name=$Matches[2];$accounts[$pending].Types[$type]++;$objects+=@{Type=$type;Name=$name;Definer=$pending};$pending=$null}
        }
        if($null-ne$pending){throw 'Definer object type unresolved'}
    } finally {$reader.Dispose();$gzip.Dispose();$stream.Dispose()}
    return [pscustomobject]@{Header=$header;Accounts=@($accounts.Values);Objects=$objects;AccountSystemStatements=$false;DatabaseSelectionStatements=$false}
}
