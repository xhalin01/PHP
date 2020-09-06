<?php
//******************KONTROLA VSTUPU A AGRUMENTU**********
mb_internal_encoding('UTF-8');
$params=parseArgs($argv,$argc);

if(($params['--input'])==false) {
    $input=utf8_encode(readStdin());
}

else {
    checkInput($params);
    $input=utf8_encode(file_get_contents($params['--input']));
}
$output=@fopen(checkOutput($params),"w");
if($output==false)
{
    fwrite(STDERR,"Vstupny soubor se neda otevrit\n");
    exit(3);
}
if($params["--header"]!=false && $params['-g']==false) {
    fwrite($output,"--".$params['--header']."\n\n");
}


//********************NAČÍTANIE VSTUPU*******************
$input=strtolower($input);
$xml = @simplexml_load_string($input);



//******************PARSOVANIE VSTUPU********************
$arr = json_decode(json_encode((array) $xml), 1); //prevod na pole
$root=new sqlTables();
$root->getNodes($xml,"");                         //ziskanie tabulke
$root->findAttributes($arr,"","");                //ziskanie elementov

if(!$params['-g']) {
    if($params['--etc']!==false) {
        $root->nodes=etcCorrect($root->nodes,$params['--etc']);  //ked bol zadany prepnac --etc tak uprav stlpce
    }
    $attributes=$root->createSimpleTables($root->attributes);    //vytvor tabulky z koncovych hodnot xml
    
    if($params['-b'])
        $root->columnCorrection();                               //korekcia stlpcov pri prepinaci -b
    $root->createIDs();                                          //vytvorenie id(cudzich klucov)

    if(!$params['-a'])
        $root->addAtributes($attributes);                        //pridanie atributov do tabuliek
    
    $root->mergeTables();                                        //spojenie tabuliek

    $root->createTables($output);                                //vypis
}
else {
    if($params['--etc']!==false) {
        $root->nodes=etcCorrect($root->nodes,$params['--etc']);  //ked bol zadany prepnac --etc tak nastane uprava stlpcov
    }
    $root->createXML($output);                                   //vypis
}


//**********ENUM***************
abstract class types
{
    const BIT = 0;
    const INT = 1;
    const FLOAT = 2;
    const NVARCHAR = 3;
    const NTEXT = 4;
}

//***********************HLAVNA TRIEDA S METÓDAMI***************
class sqlTables{
    public $nodes=array();
    public $nodeCount=array();
    public $simpleTables=array();
    public $tables=array();
    public $attributes=array();

    //funkcia získa tabulky a ich potomkov, zo vstupného xml
    function getNodes($xml,$root){
        $temp=array();
        foreach ($xml as $node => $child) {
            if(!array_key_exists($node,$this->nodes)) {
                $this->nodes[$node]=array();
            }
            if(!empty($root)) {
                if(!array_key_exists($node,$temp)) {
                    $temp[$node]=1;
                }
                else {
                    $temp[$node]++;
                }
            }
            $this->getNodes($child,$node);
        }
        $this->nodeCount=$temp;

        //priradenie počtu všetkych potomkov
        if(!empty($root)) {
            foreach ($this->nodeCount as $item => $val) {
                if(array_key_exists($item,$this->nodes[$root])) {
                    if( $this->nodeCount[$item] > $this->nodes[$root][$item]) {
                        $this->nodes[$root][$item] = $this->nodeCount[$item];
                    }
                }
                else {
                    $this->nodes[$root][$item] = $this->nodeCount[$item];
                }
            }
        }
    }


    //pri prepinaci -b sa viac rovnakých podelementov javi ako jeden
    function columnCorrection(){
        foreach ($this->nodes as $node => $child)
            foreach ($child as $grandChild => $val)
                $this->nodes[$node][$grandChild]=1;
    }


    //vypis vystupneho XML
    function createXML($output)
    {
        fwrite($output,"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
        fwrite($output,"<tables>\n");
        foreach ($this->nodes as $node => $content)
        {
            fwrite($output,"\t<table name=\"$node\">\n");
            foreach ($this->nodes as $item => $value)
            {
                fwrite($output,"\t\t<relation to=\"$item\" relation_type=\"".$this->getRelation($node,$item)."\"/>\n");
            }
            fwrite($output,"\t</table>\n");
        }
        fwrite($output,"</tables>\n");
    }


    //Ziskanie vztahu dvoch tabuliek
    function getRelation($node,$item)
    {
        if($node==$item)
            $tmp= "1:1";
        elseif ($this->findRelation($item,$node)==true && $this->findRelation($node,$item)==true){
            $tmp="N:M";
        }
        elseif($this->findRelation($item,$node)==true)
            $tmp= "N:1";
        elseif($this->findRelation($node,$item)==true)
            $tmp= "1:N";
        else
            $tmp="N:M";
        //echo "finding relation $node to $item=$tmp\n";
        return $tmp;
    }


    //Hladanie prvku v poli alebo v ich potomkoch
    function findRelation($node1,$node2){
        if(array_key_exists($node2,$this->nodes[$node1])){
            return true;
        }
        else {
            return $this->findInChild($node2,$this->nodes[$node1],1);
        }

    }

    //Hladanie prvku v potomkoch
    function findInChild($key,$arr,$depth){
        $tmp=false;
        if($depth<(count($this->nodes))){               //ochrana proti zacykleniu ak by potomok obsahoval sam seba
            if(array_key_exists($key,$arr)){
                return true;
            }
            foreach ($arr as $item => $val){
                if($arr!=$this->nodes[$item]){
                    $tmp=$this->findInChild($key,$this->nodes[$item],++$depth);
                    if($tmp)
                        break;
                }
            }
        }
        return $tmp;
    }

    //vytvorenie tabulike z koncových hodnot, oznacených ako #TAB#
    function createSimpleTables($raw){
        foreach ($raw as $arr => $content) {
            foreach ($content as $table => $val) {
                if (substr($table,-5)=="#TAB#") {
                    $tmp=substr($table,0,strlen($table)-5);
                    $tm=array();
                    $tm['value']=$val;
                    $this->simpleTables[$tmp]=$tm;
                    unset($raw[$arr][$table]);
                }
            }
        }
        return $raw;
    }


    //pridanie atributov do tabuliek
    function addAtributes($attr){
        foreach ($this->tables as $table => $collumn) {
            if(array_key_exists($table,$attr)) {
                foreach ($collumn as $item => $value) {
                    if(array_key_exists($item,$attr[$table])) {
                        fprintf(STDERR,"Key conflict");exit(90);
                    }
                }
                $this->tables[$table]=array_merge($this->tables[$table],$attr[$table]);
            }
        }
    }


    //spojenie jednoduchych a zlozenych tabuliek, kvoli jednoduchsiemu vypisu
    function mergeTables(){
        foreach ($this->simpleTables as $table => $child) {
            if(isset($this->tables[$table])) {
                foreach ($child as $item => $content) {
                    $this->tables[$table][$item]=$content;
                }
            }
            else {
                $this->tables[$table]=$child;
            }
        }
    }

    //vytvorenie identifikatorov
    function createIDs()
    {
        foreach ($this->nodes as $node => $content) {
            if(count($this->nodes)!=1) {
                foreach ($content as $item => $value) {
                    if ($value > 1) {
                        for ($i = 1; $i <= $value; $i++) {
                            $tmp = $item . $i . "_id";
                            $this->tables[$node][$tmp] = "INT";
                        }
                    } else {
                        $tmp = $item . "_id";
                        $this->tables[$node][$tmp] = "INT";
                    }
                }
                if (empty($content)) {
                    $this->tables[$node] = $content;
                }
            }
        }
    }

    //vypis tabuliek
    function createTables($output){
        foreach ($this->tables as $table => $collumns) {
            fwrite($output,"CREATE TABLE $table(\n");
            fwrite($output,"\tprk_$table"."_id INT PRIMARY KEY,\n");
            $tmp=count($collumns);
            $i=1;
            foreach ($collumns as $collumn => $type) {
                if($i==$tmp) {
                    fwrite($output,"\t".$collumn." ".$type."\n");
                }
                else {
                    fwrite($output,"\t".$collumn." ".$type.",\n");
                }
                $i++;
            }
            fwrite($output,");\n\n");
        }
    }


    //fukcia pre hladanie atributov a koncovych tabuliek
    function findAttributes($array, $parent,$grandParent){
        foreach ($array as $node => $item) {
            if($node === "@attributes") {                               //ak su to atributy pridaj ich
                foreach ($item as $attr => $val) {
                    if(isset($this->attributes[$parent][$attr])) {
                        $old=getOldType($this->attributes[$parent][$attr]);  //zistenie stareho typu
                        $new=getNewType($val,true);                          //ziskanie noveho
                        if($old<$new)
                            $this->attributes[$parent][$attr]=setTypes($new); //priradenie noveho
                    }
                    else {
                        $this->attributes[$parent][$attr]=setTypes(getNewType($val,true));
                    }
                }
            }
            elseif(is_array($item)) {                                   //ak je potomok pole tak ho prejdi znova
                if(is_numeric($node)) {
                    $this->findAttributes($item,$parent,$grandParent);
                }
                else {
                    $this->findAttributes($item,$node,$parent);
                }
            }
            else {                                                      //inak si zaznac koncove tabulky
                if(count($this->nodes)!=1) {  //ošetrenie poču elementov 1
                    if (isset($this->attributes[$parent][$node])) {
                        $old = getOldType($this->attributes[$parent][$node]);
                        $new = getNewType($item, false);
                        if ($old < $new)
                            $this->attributes[$parent][$node] = setTypes($new);
                    } else {
                        $this->attributes[$parent][$node . "#TAB#"] = setTypes(getNewType($item, false));
                    }
                }
                else{
                    if (isset($this->attributes[$parent][$node])) {
                        $old = getOldType($this->attributes[$parent][$node]);
                        $new = getNewType($item, false);
                        if ($old < $new)
                            $this->attributes[$parent][$node] = setTypes($new);
                    } else {
                        $this->attributes[$parent]["value"] = setTypes(getNewType($item, false));
                    }

                    if (isset($this->nodes[$parent][$node])) {
                        $old = getOldType($this->nodes[$parent][$node]);
                        $new = getNewType($item, false);
                        if ($old < $new)
                            $this->nodes[$parent][$node] = setTypes($new);
                    } else {
                        $this->nodes[$parent]["value"] = setTypes(getNewType($item, false));
                    }
                }
            }
        }
    }

}


//ak bol zadany prepinac --etc tak uprav pozadovane stplce
function etcCorrect($nodes,$etc){
    foreach ($nodes as $node => $child) {
        foreach ($child as $grandChild => $val)
            if($val>$etc) {
                $tmp=array();
                $tmp[$node]=1;
                if(isset($nodes[$grandChild])){
                    if(array_key_exists($node,$nodes[$grandChild]))
                    {
                        fprintf(STDERR,"Konflikt klicu pouzitim --etc\n");
                        exit(90);
                    }
                    $new=array_merge($nodes[$grandChild],$tmp);
                    $nodes[$grandChild]=$new;
                    unset($nodes[$node][$grandChild]);
                }
                else {
                    $nodes[$grandChild]=$tmp;
                    unset($nodes[$node][$grandChild]);
                }
            }
    }
    return $nodes;
}

//ziskanie typu zo stringu
function getOldType($str)
{
    if("BIT"==$str)
        return types::BIT;
    elseif("INT"==$str)
        return types::INT;
    elseif ("FLOAT"==$str)
        return types::FLOAT;
    elseif ("NVARCHAR"==$str)
        return types::NVARCHAR;
    else
        return "NTEXT";
}


//nastavenie typu z enumu
function setTypes($str)
{
    if(types::BIT==$str)
        return "BIT";
    elseif(types::INT==$str)
        return "INT";
    elseif (types::FLOAT==$str)
        return "FLOAT";
    elseif (types::NVARCHAR==$str)
        return "NVARCHAR";
    else
        return "NTEXT";
}


//zistenie typu pomocou regularneho vyrazu
function getNewType($str,$attribute){
    $str=trim($str);
    if(empty($str) || $str==="true" || $str==="false" || $str=="0" || $str=="1")
        return types::BIT;
    elseif(preg_match("/^[-+]?[0-9]+$/",$str))
        return types::INT;
    elseif (preg_match("/^[-+]?[0-9]*.?[0-9]*([eE][-+]?[0-9]+)?$/",$str))
        return types::FLOAT;
    elseif ($attribute)
        return types::NVARCHAR;
    else
        return types::NTEXT;
}


//kontrolovanie existencie vstupu
function checkInput($params)
{
    if(!file_exists($params['--input'])) {
        fileError("Vstupny soubor neexistuje");
    }
    elseif(!is_readable($params['--input'])) {
        fileError("Vstupny soubor se neda cist");
    }
}

//citanie standardneho vstupu
function readStdin(){
    $f = fopen( 'php://stdin', 'r' );
    $input = null;
    while( $line = fgets( $f ) ) {
        $input.=$line;
    }
    return $input;
}

//kontrolovanie vystupneho suboru
function checkOutput($params){
    if($params['--output']!=false) {
        if(file_exists($params['--output'])) {
            if (!is_writable($params['--output'])) {
                fileError("Nedostatecna prava na zapis");
            }
        }
        return $params['--output'];
    }
    else {  //neni zadany vystup
        return 'php://stdout';
    }

}


//rozdelenie vstupnych prepinacov do asociativneho pola, a kontrola chybnych kombinacii
function parseArgs($argv,$argc)
{
    $params = array();
    $params['--input']=false;
    $params['--output']=false;
    $params['--header']=false;
    $params['--etc']=false;
    $params['-a']=false;
    $params['-b']=false;
    $params['-g']=false;
    $input=false;
    $output=false;

    for($i=1;$i<$argc;$i++) {
        if($argv[$i]=="--help") {
            if($argc==2) {
                printHelp();
            }
            else {
                paramsError("--help se nesmi kombinovat s jinima prepinacema");
            }
        }
        else if(substr($argv[$i],0,8)=="--input=") {
            if($input==false) {
                $params['--input']=substr($argv[$i],8);
                $input=true;
            }
            else {
                paramsError("--input zadany vice krat");
            }
        }
        else if(substr($argv[$i],0,9)=="--output=") {
            if($output==false) {
                $params['--output']=substr($argv[$i],9);
                $output=true;
            }
            else {
                paramsError("--output zadany vice krat");
            }
        }
        else if(substr($argv[$i],0,9)=="--header=") {
                $params['--header']=substr($argv[$i],9,-1);   //jak osetrit chybnu hlavicku?
        }
        else if(substr($argv[$i],0,6)=="--etc=") {
            if(($params['-b'])==false) {
                if(substr($argv[$i],6)>=0 && is_numeric(substr($argv[$i],6))) {
                    $params['--etc']=substr($argv[$i],6);
                }
                else {
                    paramsError("chybne zadane --etc");
                }
            }
            else {
                paramsError("--etc nesmi byt kombinovane s -b");
            }
        }
        else  if($argv[$i]=="-a") {
            $params['-a']=true;
        }

        else if($argv[$i]=="-b") {
            if(!is_numeric(($params['--etc']))) {
                $params['-b']=true;
            }
            else {
                paramsError("--etc nesmi byt kombinovane s -b");
            }
        }
        else if($argv[$i]=="-g") {
            $params['-g']=true;
        }
        else {
            paramsError($argv[$i]);
        }
    }
    return $params;
}

//vypis napovedy
function printHelp(){
    echo "Napoveda:
--help                 - zobrazi napovedu
--input=filename.ext   - vstupny xml soubor
--output=filename.ext  - vystupny soubor
--header='text'        - hlavicka pripsana na zacatek souboru
--etc=num              - maximalny pocet stlpcu stejneho elementu
-a                     - sloupce z atributu nejsou generovane
-b                     - vice rovnakych elementu se jevi jako jeden
-g                     - xml se vztahmy na vystupu\n";
    exit(0);
}

//vypis chyby parametrov
function paramsError($err){
    fwrite(STDERR, "Spatny parametry, pouzij prepinac --help ($err)\n");
    exit(1);
}

//vypis chyby suboru
function fileError($err){
    fwrite(STDERR, "$err\n");
    exit(2);
}

?>
