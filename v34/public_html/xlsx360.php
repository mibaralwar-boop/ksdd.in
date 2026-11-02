<?php
declare(strict_types=1);
// KSDD CRM 360 — minimal, safe .xlsx reader (no Composer needed; uses PHP zip + XMLReader which Hostinger provides).
// Reads CACHED cell values only: formulas, macros, external links and embedded objects are never evaluated or followed.
// Guards: compressed/uncompressed size limits (zip-bomb), row/column limits, no network/entity loading.

const XLSX_MAX_BYTES = 5000000;        // uploaded file
const XLSX_MAX_UNCOMPRESSED = 40000000; // total of the XML parts we read
const XLSX_MAX_ROWS = 3000;
const XLSX_MAX_COLS = 60;

function xlsx_col_index(string $ref): int { $l=preg_replace('/[^A-Z]/','',strtoupper($ref)); $n=0; for($i=0;$i<strlen($l);$i++) $n=$n*26+(ord($l[$i])-64); return $n-1; }
function xlsx_part(ZipArchive $z,string $name): ?string {
    $st=$z->statName($name); if($st===false) return null;
    if($st['size']>XLSX_MAX_UNCOMPRESSED) fail('The Excel file is too large to read safely.');
    $s=$z->getFromName($name); return $s===false?null:$s;
}
function xlsx_xml(string $xml): SimpleXMLElement {
    $prev=libxml_use_internal_errors(true);
    $x=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT);
    libxml_use_internal_errors($prev); if($x===false) fail('The Excel file is damaged or not a real .xlsx workbook.'); return $x;
}
/** @return array{sheet:string,sheets:array<int,string>,rows:array<int,array<int,string>>} first worksheet (or $want) as rows of strings */
function xlsx_read(string $data,?string $want=null): array {
    if(strlen($data)>XLSX_MAX_BYTES) fail('Excel file is larger than 5 MB.',413);
    if(!class_exists('ZipArchive')) fail('This server cannot read .xlsx (PHP zip extension missing). Save the sheet as CSV and upload that instead.',503);
    if(substr($data,0,2)!=="PK") fail('This is not an .xlsx file. Use Excel “Save As → Excel Workbook (.xlsx)” or CSV.');
    $tmp=tempnam(sys_get_temp_dir(),'ksx'); file_put_contents($tmp,$data);
    $z=new ZipArchive(); if($z->open($tmp)!==true){ @unlink($tmp); fail('The Excel file could not be opened.'); }
    try{
        $total=0; for($i=0;$i<$z->numFiles;$i++){ $st=$z->statIndex($i); $total+=(int)$st['size']; if(preg_match('/vbaProject|\.bin$/i',(string)$st['name'])) continue; }
        if($total>XLSX_MAX_UNCOMPRESSED*2) fail('The Excel file expands to an unsafe size.');
        $wb=xlsx_part($z,'xl/workbook.xml'); if($wb===null) fail('Workbook part missing: not an Excel .xlsx file.');
        $rels=xlsx_part($z,'xl/_rels/workbook.xml.rels');
        $relMap=[]; if($rels!==null){ foreach(xlsx_xml($rels)->Relationship as $r){ $relMap[(string)$r['Id']]=(string)$r['Target']; } }
        $wx=xlsx_xml($wb); $sheets=[]; $targets=[];
        foreach($wx->sheets->sheet as $sh){ $name=(string)$sh['name']; $rid=(string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id']; $sheets[]=$name; $t=$relMap[$rid]??''; $t=ltrim(preg_replace('#^/?xl/#','',$t),'/'); $targets[$name]='xl/'.($t?:'worksheets/sheet1.xml'); }
        if(!$sheets) fail('The workbook has no worksheets.');
        $name=$want!==null&&isset($targets[$want])?$want:$sheets[0];
        $shared=[]; $ss=xlsx_part($z,'xl/sharedStrings.xml');
        if($ss!==null){ foreach(xlsx_xml($ss)->si as $si){ if(isset($si->t)) $shared[]=(string)$si->t; else { $t=''; foreach($si->r as $r) $t.=(string)$r->t; $shared[]=$t; } } }
        $sx=xlsx_part($z,$targets[$name]); if($sx===null) fail('Worksheet “'.$name.'” could not be read.');
        $rows=[]; $r=new XMLReader(); if(!$r->XML($sx,null,LIBXML_NONET)) fail('Worksheet XML is not readable.');
        $cur=null; $rowIdx=-1;
        while($r->read()){
            if($r->nodeType!==XMLReader::ELEMENT) continue;
            if($r->localName==='row'){ $rowIdx=((int)$r->getAttribute('r'))-1; if($rowIdx<0)$rowIdx=count($rows); if($rowIdx>=XLSX_MAX_ROWS) fail('The sheet has more than '.XLSX_MAX_ROWS.' rows.'); $rows[$rowIdx]=$rows[$rowIdx]??[]; continue; }
            if($r->localName==='c'){
                $ref=(string)$r->getAttribute('r'); $type=(string)$r->getAttribute('t'); $col=$ref!==''?xlsx_col_index($ref):count($rows[$rowIdx]??[]);
                if($col>=XLSX_MAX_COLS) { $r->next(); continue; }
                $node=$r->readOuterXml(); $cx=xlsx_xml($node); $val='';
                if($type==='inlineStr') { $val=(string)($cx->is->t??''); if($val===''&&isset($cx->is->r)) foreach($cx->is->r as $rr) $val.=(string)$rr->t; }
                elseif(isset($cx->v)) { $v=(string)$cx->v; $val=$type==='s'?($shared[(int)$v]??''):($type==='b'?($v==='1'?'TRUE':'FALSE'):$v); }
                if($rowIdx<0){ $rowIdx=0; $rows[0]=[]; }
                $rows[$rowIdx][$col]=trim($val);
            }
        }
        $r->close();
        ksort($rows); $out=[]; foreach($rows as $cells){ if(!$cells) { $out[]=[]; continue; } $max=max(array_keys($cells)); $line=[]; for($i=0;$i<=$max;$i++) $line[]=$cells[$i]??''; $out[]=$line; }
        return ['sheet'=>$name,'sheets'=>$sheets,'rows'=>$out];
    } finally { $z->close(); @unlink($tmp); }
}
