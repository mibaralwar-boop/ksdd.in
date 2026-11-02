<?php
declare(strict_types=1);
// KSDD — minimal, safe reader for legacy Excel 97-2003 .xls (BIFF8 inside an OLE2 compound file), e.g. Vyapar exports.
// Reads cell VALUES only (strings, numbers, cached formula numbers). No macros, no external links. Pure PHP, no extensions.
const XLS_MAX_BYTES = 15000000;

function xls_u16(string $s,int $o): int { return ord($s[$o])|(ord($s[$o+1])<<8); }
function xls_u32(string $s,int $o): int { return unpack('V',substr($s,$o,4))[1]; }
function xls_i32(string $s,int $o): int { $v=unpack('V',substr($s,$o,4))[1]; return $v>=0x80000000?$v-0x100000000:$v; }

/** Extract the "Workbook" (or "Book") stream from an OLE2 compound file. */
function xls_ole_stream(string $d): string {
    if(strlen($d)>XLS_MAX_BYTES) fail('The .xls file is too large (max 15 MB).',413);
    if(substr($d,0,8)!=="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") fail('This is not an Excel 97-2003 .xls file.');
    $ss=1<<xls_u16($d,30); $mss=1<<xls_u16($d,32); $nFat=xls_u32($d,44); $dirStart=xls_u32($d,48); $miniCut=xls_u32($d,56); $miniFatStart=xls_u32($d,60); $difStart=xls_u32($d,68); $nDif=xls_u32($d,72);
    if($ss!==512&&$ss!==4096) fail('Unsupported .xls sector size.');
    $sec=fn(int $n)=>substr($d,($n+1)*$ss,$ss);
    $fatSecs=[]; for($i=0;$i<109&&count($fatSecs)<$nFat;$i++){ $v=xls_u32($d,76+$i*4); if($v>=0xFFFFFFFA) break; $fatSecs[]=$v; }
    $dif=$difStart; $guard=0; while(count($fatSecs)<$nFat&&$dif<0xFFFFFFFA&&$guard++<$nDif+2){ $b=$sec($dif); $per=$ss/4-1; for($i=0;$i<$per&&count($fatSecs)<$nFat;$i++){ $fatSecs[]=xls_u32($b,$i*4); } $dif=xls_u32($b,$per*4); }
    $fat=[]; foreach($fatSecs as $fs){ $b=$sec($fs); $fat=array_merge($fat,array_values(unpack('V*',$b))); }
    $chain=function(int $start)use($fat,$sec): string { $o=''; $n=$start; $seen=[]; while($n<0xFFFFFFFA){ if(isset($seen[$n])||!isset($fat[$n])) fail('The .xls file is damaged (sector chain).'); $seen[$n]=1; $o.=$sec($n); $n=$fat[$n]; if(strlen($o)>XLS_MAX_BYTES*2) fail('The .xls file is damaged.'); } return $o; };
    $dir=$chain($dirStart); $entries=[];
    for($p=0;$p+128<=strlen($dir);$p+=128){ $nl=xls_u16($dir,$p+64); if($nl<2) continue; $name=mb_convert_encoding(substr($dir,$p,$nl-2),'UTF-8','UTF-16LE'); $entries[]=['name'=>$name,'type'=>ord($dir[$p+66]),'start'=>xls_u32($dir,$p+116),'size'=>xls_u32($dir,$p+120)]; }
    $root=null; $wb=null; foreach($entries as $e){ if($e['type']===5) $root=$e; if(in_array($e['name'],['Workbook','Book'],true)) $wb=$e; }
    if(!$wb) fail('No workbook found inside the .xls file.');
    if($wb['size']>=$miniCut) return substr($chain($wb['start']),0,$wb['size']);
    // small stream: lives in the mini stream
    $ministream=$chain($root['start']); $mfat=[]; $n=$miniFatStart; while($n<0xFFFFFFFA){ $mfat=array_merge($mfat,array_values(unpack('V*',$sec($n)))); $n=$fat[$n]??0xFFFFFFFE; }
    $o=''; $n=$wb['start']; $g=0; while($n<0xFFFFFFFA&&$g++<100000){ $o.=substr($ministream,$n*$mss,$mss); $n=$mfat[$n]??0xFFFFFFFE; } return substr($o,0,$wb['size']);
}
/** Decode an RK number. */
function xls_rk(int $rk): float { $signed=$rk>=0x80000000?$rk-0x100000000:$rk; $v=($rk&2)?(float)($signed>>2):unpack('d',"\0\0\0\0".pack('V',$rk&0xFFFFFFFC))[1]; return ($rk&1)?$v/100:$v; }
/**
 * @return array<string,array<int,array<int,string>>> sheetName => rows => cols => string value
 */
function xls_read_all(string $data): array {
    $w=xls_ole_stream($data); $len=strlen($w); $pos=0; $sheets=[]; $sst=[]; $cur=null; $out=[];
    // Pass 1: records list (merge CONTINUE for SST)
    $recs=[]; while($pos+4<=$len){ $id=xls_u16($w,$pos); $sz=xls_u16($w,$pos+2); $recs[]=[$id,substr($w,$pos+4,$sz),$pos]; $pos+=4+$sz; }
    $n=count($recs);
    for($i=0;$i<$n;$i++){ [$id,$b,$at]=$recs[$i];
        if($id===0x85){ $off=xls_u32($b,0); $nl=ord($b[6]); $fl=ord($b[7]); $nm=$fl&1?mb_convert_encoding(substr($b,8,$nl*2),'UTF-8','UTF-16LE'):mb_convert_encoding(substr($b,8,$nl),'UTF-8','ISO-8859-1'); $sheets[$off]=$nm; }
        elseif($id===0xFC){ // SST with CONTINUE records: strings may be split, and the 16-bit flag is restated at each CONTINUE
            $parts=[$b]; while($i+1<$n&&$recs[$i+1][0]===0x3C){ $parts[]=$recs[++$i][1]; }
            $total=xls_u32($b,4); $pi=0; $p=8; $buf=$parts[0];
            $need=function(int $k)use(&$buf,&$p,&$pi,$parts){ while($p+$k>strlen($buf)&&$pi+1<count($parts)){ $buf=substr($buf,$p).$parts[++$pi]; $p=0; } };
            for($s=0;$s<$total;$s++){ $need(3); if($p+3>strlen($buf)) break; $cch=xls_u16($buf,$p); $fl=ord($buf[$p+2]); $p+=3; $rt=0; $ext=0;
                if($fl&8){ $need(2); $rt=xls_u16($buf,$p); $p+=2; } if($fl&4){ $need(4); $ext=xls_u32($buf,$p); $p+=4; }
                $str=''; $left=$cch; $wide=$fl&1;
                while($left>0){ $avail=strlen($buf)-$p; $bytesPer=$wide?2:1; $take=min($left,intdiv($avail,$bytesPer));
                    if($take>0){ $chunk=substr($buf,$p,$take*$bytesPer); $str.=$wide?mb_convert_encoding($chunk,'UTF-8','UTF-16LE'):mb_convert_encoding($chunk,'UTF-8','ISO-8859-1'); $p+=$take*$bytesPer; $left-=$take; }
                    if($left>0){ if($pi+1>=count($parts)) break; $buf=$parts[++$pi]; $p=0; $wide=ord($buf[0])&1; $p=1; } }
                $skip=$rt*4+$ext; while($skip>0){ $avail=strlen($buf)-$p; if($skip<=$avail){ $p+=$skip; $skip=0; } else { $skip-=$avail; if($pi+1>=count($parts)) break; $buf=$parts[++$pi]; $p=0; } }
                $sst[]=$str; }
        }
        elseif($id===0x809){ $type=xls_u16($b,2); if($type===0x10){ $cur=$sheets[$at]??('Sheet'.count($out)); $out[$cur]=[]; } else $cur=null; }
        elseif($cur!==null){
            $set=function(int $r,int $c,string $v)use(&$out,$cur){ if($r>20000||$c>200) return; $out[$cur][$r][$c]=$v; };
            if($id===0xFD){ $set(xls_u16($b,0),xls_u16($b,2),(string)($sst[xls_u32($b,6)]??'')); }
            elseif($id===0x203){ $set(xls_u16($b,0),xls_u16($b,2),xls_num(unpack('d',substr($b,6,8))[1])); }
            elseif($id===0x27E){ $set(xls_u16($b,0),xls_u16($b,2),xls_num(xls_rk(xls_u32($b,6)))); }
            elseif($id===0xBD){ $r=xls_u16($b,0); $c=xls_u16($b,2); $last=xls_u16($b,strlen($b)-2); for($k=0;$c+$k<=$last;$k++) $set($r,$c+$k,xls_num(xls_rk(xls_u32($b,4+$k*6+2)))); }
            elseif($id===0x204){ $nl=xls_u16($b,6); $fl=ord($b[8]); $set(xls_u16($b,0),xls_u16($b,2),$fl&1?mb_convert_encoding(substr($b,9,$nl*2),'UTF-8','UTF-16LE'):mb_convert_encoding(substr($b,9,$nl),'UTF-8','ISO-8859-1')); }
            elseif($id===0x06){ $r=xls_u16($b,0); $c=xls_u16($b,2); if(xls_u16($b,12)!==0xFFFF) $set($r,$c,xls_num(unpack('d',substr($b,6,8))[1])); elseif($i+1<$n&&$recs[$i+1][0]===0x207){ $sb=$recs[$i+1][1]; $nl=xls_u16($sb,0); $fl=ord($sb[2]); $set($r,$c,$fl&1?mb_convert_encoding(substr($sb,3,$nl*2),'UTF-8','UTF-16LE'):mb_convert_encoding(substr($sb,3,$nl),'UTF-8','ISO-8859-1')); } }
        }
    }
    foreach($out as $k=>$rows){ ksort($rows); $o=[]; foreach($rows as $ri=>$cells){ $m=max(array_keys($cells)); $line=[]; for($c=0;$c<=$m;$c++) $line[]=trim($cells[$c]??''); $o[$ri]=$line; } $out[$k]=array_values($o); }
    return $out;
}
function xls_num(float $f): string { return (abs($f-round($f))<1e-9&&abs($f)<1e15)?(string)(int)round($f):rtrim(rtrim(sprintf('%.6F',$f),'0'),'.'); }
/** Read .xls or .xlsx into sheetName => rows (strings). */
function sheet_read_any(string $data): array {
    if(substr($data,0,2)==='PK'){ require_once __DIR__.'/xlsx360.php'; $first=xlsx_read($data); $o=[$first['sheet']=>$first['rows']]; foreach($first['sheets'] as $s) if($s!==$first['sheet']) $o[$s]=xlsx_read($data,$s)['rows']; return $o; }
    return xls_read_all($data);
}
