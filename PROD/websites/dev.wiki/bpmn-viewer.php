<?php
////////////////////////////////////
/// Обязательно установить: sudo apt-get install php-curl
////////////////////////////////////
/// Эта версия настроена на работу в сочетании с расширением VCSystem для MediaWiki
/// Arsenii Gorkin
/// gorkin@protonmail.com
/// ////////////////////////////////


// Проверяем входные данные
//if (!$_GET["url"]) {
if (!$_GET["mw-filename"]) {
    header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
    echo "Параметры не были переданы";
    exit;
}
//else if (!preg_match('/[\w-]+\.bpmn$/', $_GET["url"])){
else if (!preg_match('/[\w-]+\.bpmn$/', $_GET["mw-filename"])){
    header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
//    echo "Wrong path has been given";
    echo "Указан неверный путь к BPMN диаграмме: ". $_GET["mw-filename"];
    exit;
}

// Отсекаем всё начало пути к файлу (если имеется абс. путь)
//$url = preg_replace('/(^\w+\:\/\/[^\/]+\/)(uploads\/\w+\/\w+\/.+\.bpmn)$/', '$2', $_GET["url"]);

$mw_filename = $_GET["mw-filename"];

//$baseURL = 'http://139.59.191.178/index.php?File:';
// $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$protocol = 'https';
$hostname = $_SERVER['HTTP_HOST'];
$baseURL = $protocol . "://" . $hostname . "/index.php/File:";
$vcsBranchFromCookies = $_COOKIE['brnch'] ?? 'Master';
$mwSessionCookies = $_COOKIE['gpidru_wiki_wiki_session'];
//$mwUserSessionCookies = $_COOKIE['gpidru_wiki_wikimwuser-sessionId'];
$mwUserIDCookies = $_COOKIE['gpidru_wiki_wikiUserID'];

if(!$mwSessionCookies || !$mwUserIDCookies){
    header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
    echo "Необходимо пройти авторизацию в данной Wiki";
    exit;
}

if (!preg_match('/^[\w-]{3,10}$/i', $vcsBranchFromCookies)) {
    header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
    echo "Неверный формат ветки VCSystem";
    exit;
}

// Забираем файл в переменную
//$bpmnXML = file_get_contents('/var/www/html/'.$url);
$ch = curl_init($baseURL.$mw_filename);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // для следования редиректам
curl_setopt($ch, CURLOPT_HEADER, true);  // для получения заголовков
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Cookie: vcs_isEditMode=0; vcs_brnch=$vcsBranchFromCookies; gpidru_wiki_wiki_session=$mwSessionCookies; gpidru_wiki_wikiUserID=$mwUserIDCookies"
));
$content = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Разделяем заголовки и тело ответа
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers_raw = substr($content, 0, $header_size);
$body = substr($content, $header_size);

curl_close($ch);

$headers = explode("\r\n", $headers_raw);  // Разделяем заголовки по строкам

$cvs_isBranchOk = null;
foreach ($headers as $header) {
    if (strpos($header, 'cvs_isBranchOk:') !== false) {
        $cvs_isBranchOk = trim(str_replace('cvs_isBranchOk:', '', $header));
        break;
    }
}

// Обрабатываем только, если ветки нет
if ($cvs_isBranchOk && $cvs_isBranchOk == 'no') {

    header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
    echo "Неверная ветка VCSystem.";
    exit;
}

if ($http_status == 200 && $body !== false) {
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
        echo "Содержимое не является корректным BPMN-XML. Возможно, Вы не авторизовались в Wiki-системе.";
        exit;
    }
    $bpmnXML = $body;
}
else {
    header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
    echo "Ошибка при получении данных BPMN диаграммы";
    exit;
}

// Функция для удаления групп
function groupDelete(&$groupXML, &$bpmnXML, &$groupsCatBlock, &$allGroupsStr = "") {

    // определяем ID группы
    preg_match('/bpmnElement=\"([\w\-\.]+)\"/', $groupXML, $groupsToRemove);

    // определяем ID категории группы (если есть)
    preg_match('/(?:\<bpmn\:group *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id="'.$groupsToRemove[1].'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*categoryValueRef="([\w\.\-]+)" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/\>)/', $bpmnXML, $groupsToRemoveCatID);

    // Удаляем саму группу внутри пула (на диаграмме)
    $bpmnXML = preg_replace('/( *\<bpmndi\:BPMNShape *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*bpmnElement=\"'.$groupsToRemove[1].'" *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*\>[\n ]*\<dc:Bounds *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*\/?\>(?:[\n ]*\<bpmndi:BPMNLabel\>[\n ]*\<dc:Bounds *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*\/?\>[\n ]*\<\/bpmndi:BPMNLabel\>)?[\n ]*\<\/bpmndi\:BPMNShape>\n)/', "\n", $bpmnXML);

    // Удаляем объявление группы в начале файла (внутри collaboration)
    $bpmnXML = preg_replace('/(\<bpmn\:group *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id="'.$groupsToRemove[1].'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/\>)/', "\n", $bpmnXML);

    // Если есть категория у группы, то её удаляем тоже (находится между последним процессом и началом диаграмм)
    if ($groupsToRemoveCatID[1]) {
        $groupsCatBlock = preg_replace('/(<bpmn\:category id="[\w\-\.]+">[\n\t ]*<bpmn\:categoryValue id="'.$groupsToRemoveCatID[1].'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)* \/>[\n\t ]*<\/bpmn:category>)/', "\n", $groupsCatBlock);
    }

    // Если переданы группы отдельно (из-под expanded подпроцесса)
    if ($allGroupsStr != ""){
        $allGroupsStr = preg_replace('/ *\<bpmn\:group *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id="'.$groupsToRemove[1].'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/\>/', '', $allGroupsStr);
    }

    return 1;
}

//$bpmnXML = file_get_contents($baseURL.$url.);

//header('Content-Type: application/xml; charset=utf-8');

// Наследуем информацию (documentation) из всех процессов в callActivity элементы, которые ссылаются на эти процессы для вывода тултипов
preg_match_all('/<bpmn:callActivity [^>]*>[ \n]*(?:<[^<]*>(?:[^<]*<[^<]*>)*[\n ]*)*[ \n]*<zeebe:calledElement *(?:[ \w\.\-]+\=\"[^\"]+\" *)*processId="([\w\.-_]+)" [\S\s]*<\/bpmn:incoming>/U', $bpmnXML, $allCallActivityLinkIDs);

$docsDict = array();
// Удаляем дубликаты
$allCallActivityLinkIDs[1] = array_unique($allCallActivityLinkIDs[1]);
// Собираем словарь с документацией по каждому процессу/подпроцессу и связываем её по ID
foreach($allCallActivityLinkIDs[1] as &$thisID){
//        $thisID = preg_replace('/[^\w\d\._-]/', '', $thisID);
    $thisDocumentationStr = "Документация (под-)процессу, на который ссылкается данный элемент, не найдена.";

    // Вытаскиваем доку из ссылаемого (под-)процесса
    preg_match('/<bpmn:(?:subP|p)rocess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id="'.$thisID.'"[^>]*>(?:(?=[\n][ ]*<bpmn:subProcess)[\n][ ]*<[^>]*>(?:[^<]*<[^>]*>))*[ \n]*<bpmn:documentation>([^<]*)<\/bpmn:documentation>/', $bpmnXML, $thisDocumentationArr);

    $thisDocumentationStr = $thisDocumentationArr[1];
    $docsDict[$thisID] = $thisDocumentationStr;
}
unset($allCallActivityLinkIDs, $thisID, $thisDocumentationStr, $thisDocumentationArr);

$doIt = 0;
$thisID = 0;
$tempReplacer = "";
$tempOrig = "";
foreach(array_reverse(preg_split("/((\r?\n)|(\r\n?))/", $bpmnXML)) as $thisLine){

    if ($doIt == 0) {
        preg_match('/<zeebe:calledElement  *(?:[ \w\.\-]+\=\"[^\"]+\" *)*processId="([\w\d\-\.]*)"/', $thisLine, $thisIDArr);
        if ($thisIDArr[1]) {
            $thisID = $thisIDArr[1];
            $doIt = 1;

            // записываем в ОБРАТНОМ порядке, так как foreach в реверсе!
            $tempOrig = $thisLine."\n";
        }
    }
    elseif ($doIt == 1) {

        // Проверяем - есть ли тут уже дока (в этом callActivity)? Если есть - оставляем.
        if (preg_match('/<bpmn:documentation>/', $thisLine)) {

            $doIt = 0;
            $tempOrig = "";
            $thisID = 0;
        }
        elseif (preg_match('/ *<bpmn:callActivity/', $thisLine)) {

            // записываем в ОБРАТНОМ порядке, так как foreach в реверсе!
            $tempOrig = $thisLine."\n".$tempOrig;
            $tempReplacer = preg_replace('/( *)(<bpmn:extensionElements>)/', '$1<bpmn:documentation>'.$docsDict[$thisID].'</bpmn:documentation>'."\n".'$1$2', $tempOrig);
            $bpmnXML = str_replace($tempOrig, $tempReplacer, $bpmnXML);
            $doIt = 0;
            $tempReplacer = "";
            $thisID = 0;
        }
        else{
            // записываем в ОБРАТНОМ порядке, так как foreach в реверсе!
            $tempOrig = $thisLine."\n".$tempOrig;
        }

    }
}


// Если PID указан и указан неверно
if ($_GET["pid"] and preg_match('/^[\w\.\-]+$/', $_GET["pid"])) {

    $pid = $_GET["pid"];
    // Проверяем - есть ли такой PID (ищем по диаграммам, поскольку процесс должен иметь обязательно собственную диаграмму)
    if (!preg_match('/\<bpmn\:process *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=\"'.$pid.'\"/', $bpmnXML ) and !preg_match('/\<bpmn\:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=\"'.$pid.'\"/', $bpmnXML ) ) {
        header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
        echo "PID (id процесс/подпроцесса) указан неверно, либо он ещё не создан в данной диаграмме";
        exit;
    }

    // Проверяем - PID - это процесс или подпроцесс
    // подпроцесс
    if (preg_match('/<bpmn:subProcess id=\"'.$pid.'\"/', $bpmnXML )){
        // подпроцесс
        // Записываем отступы перед субпроцессом (так как может быть вложенный субпроцесс и нам нужно его охватить)
        //TODO: Улучшить подход, чтобы отказаться от работы с отступами
        preg_match('/( +)(?=\<bpmn\:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=\"'.$pid.'\")/', $bpmnXML, $subprocessIndets );



        // Ищем родителей
        // Копируем всё до этого подпроцесса
        preg_match('/^[\n .\<\>\=\w\:\/\-\"\#\s\S]*\<bpmn\:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id\=\"'.$pid.'\"/', $bpmnXML, $subprocessPreData );
        $parrentLinksArr = array();
        $parrentsIndents = $subprocessIndets[1];
        while(1){
            $parrentsIndentsBefore = $parrentsIndents;

            // Удаляем один отступ и ищем последнего родителя с таким отступом (именно он и есть наш)
            $parrentsIndents = preg_replace('/ /', '',  $parrentsIndents, 1);

            if ($parrentsIndents == $parrentsIndentsBefore){
                break;
            }
            preg_match_all('/(?:(?:\n'.$parrentsIndents.')(?:\<bpmn\:(?:(?:subProcess)|(?:process)) *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id\=\"([\w\.\-]+)\"))/', $subprocessPreData[0], $parrentsID );

            // Формируем список
            if ($parrentsID[1]) {
                $parrentLinksArr[] = $parrentsID[1][count($parrentsID[1])-1];
            }
            unset($parrentsID);
        }
        unset($parrentsIndents, $parrentsIndentsBefore, $parrentsID);

        // Сохраняем весь подпроцесс
//      preg_match('/(\<bpmn\:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id\=\"'.$pid.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>(?:(?!'.$subprocessIndets[1].'\<\/bpmn:subProcess\>\n)(?:[ \n]*\<[\:\w\-\"\/\= \.]+\>[\n ]*(?:[^\<\>]+\<[\:\w\-\"\/\= \.]+\>)?\n))*'.$subprocessIndets[1].'\<\/bpmn:subProcess\>)/', $bpmnXML, $subprocess );
//        preg_match('/(\<bpmn\:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id\=\"'.$pid.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>(?:[\n .\<\>\=\w\:\/\-\"\#]*'.$subprocessIndets[1].'\<\/bpmn:subProcess\>))/', $bpmnXML, $subprocess );
        $subprocess = "";
        $checker = 1;
        foreach(explode("\n", $bpmnXML) as &$thisLine){
            if ($checker == 1) {

                if (preg_match('/^('.$subprocessIndets[1].'\<bpmn\:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id\=\"'.$pid.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>)/', $thisLine)) {
                    $subprocess .= $thisLine."\n";
                    $checker = 2;
                    continue;
                }
            }
            else{
                $subprocess .= $thisLine."\n";
                if (preg_match('/^'.$subprocessIndets[1].'\<\/bpmn:subProcess\>/', $thisLine)) {
                    break;
                }
            }
        }
        unset($thisLine, $checker, $subprocessPreData);

        // Проверяем - если подпроцесс пустой
//        if ($subprocess == ""){
        if (preg_match('/ *<bpmn:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*>[ \n]*<bpmn:documentation>[^<]*<\/bpmn:documentation>[ \n]*<\/bpmn:subProcess>/', $subprocess) or preg_match('/ *<bpmn:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*>[ \n]*<\/bpmn:subProcess>/', $subprocess) or $subprocess == ""){
//            header($_SERVER["SERVER_PROTOCOL"]." 204 No Content");
            echo "Этот подпроцесс, пока что, ничего не содержит внутри";
            exit;
        }

        $spDiagramOutput = ""; // Для диаграммы текущего подпроцесса
        $spGroups = ""; // здесь будут храниться группы (теги)
        $spCatGroups = ""; // здесь будут храниться категории групп
        $allGroupsStr = ""; // здесь будут храниться группы из родителя (для expanded случая)
        $allGroupsCatsBlocksStr = ""; // для категорий групп (в expanded режиме)
        $groupsIDs = array(); // тут будут храниться ID всех групп из родителя

        // Делаем вариант для  избавления от ошибки при работе с регулярками (точка - это любой символ, а нам нужно получить именно точку)
        $pidDot = str_replace('.', '\.', $pid);

        // Обработка, если подпроцесс в expanded состоянии
        if (preg_match('/(?:\<bpmndi\:BPMNShape *(?:[ \w\.\-]+\=\"[^\"]+\" *)*bpmnElement=\"'.$pidDot.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*isExpanded=\"true\")/', $bpmnXML)) {

            // Сохраняем все категории групп
            $allGroupsCatsBlocksStr = "";
            preg_match_all('/ *<bpmn:category id="[\w\-\.]+">[\n ]*<bpmn:categoryValue id="[\w\-\.]+" value="[^\"]+" \/>[\n ]*<\/bpmn:category>/', $bpmnXML, $allGroupsCatsBlocksArr );
            foreach($allGroupsCatsBlocksArr[0] as &$thisCatGr){
                $allGroupsCatsBlocksStr .= $thisCatGr;
            }
            unset($thisCatGr, $allGroupsCatsBlocksArr);

            // Сохраняем все группы, внутри родительского процесса, так как там размещены и те группы, которые относятся к нашему подпроцессу
            // Копируем отсутуп в родителе, чтобы найти группы внутри именно него
            preg_match('/( *)<bpmn:process id="'.$parrentLinksArr[0].'"/', $bpmnXML, $parrentIndent);

            // Копируем всего родителя
            preg_match('/('.$parrentIndent[1].'\<bpmn\:(?:subP|p)rocess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id\=\"'.$parrentLinksArr[0].'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>[\w\-.\=\"\S\s ]*  \<\/bpmn\:(?:subP|p)rocess\>)/', $bpmnXML, $parrentBlock);

            // Собираем все группы внутри этого родителя (в корне его), добавив 2 пропуска к родительскому
            preg_match_all('/('.$parrentIndent[1].'  <bpmn:group *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/>)/', $parrentBlock[1], $allGroupsArr);

            foreach ($allGroupsArr[1] as &$thisGroup){
                preg_match('/id=\"([\w\-\.]+)\"/', $thisGroup, $thisGroupID);
                $groupsIDs[] = $thisGroupID[1];
                $allGroupsStr .= $thisGroup."\n";
            }
            unset($thisGroup, $allGroupsArr);

            // Сохраняем диаграмму подпроцесса
            preg_match('/(?:<bpmndi:BPMNShape *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)*bpmnElement=\"'.$pid.'\" *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)*\>(?:(?!\<\/bpmndi:BPMNDiagram)(?:[ \n]*\<(?:[ \/]*[\w\:\-\.]+ *(?:[\w\:\-\.]+\=\"[^\"]+\" *)*)[ \/]*\>[\n ]*)[\w\-]*)*[\n ]*<\/bpmndi:BPMNDiagram>)/', $bpmnXML, $spDiagram );
            $spDiagramOutput = $spDiagram[0];

            // Форматируем диаграмму в рабочую
            $spDiagramOutput = preg_replace('/^<bpmndi:BPMNShape *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)*(?:( *id="[\w\.\-]+") *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)*(bpmnElement="[\w\.\-]+")|(( *bpmnElement="[\w\.\-]+") *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)*(id="[\w\.\-]+"))) *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)* *\>/', '  <bpmndi:BPMNDiagram id="BPMNDiagram_1">'."\n".'    <bpmndi:BPMNPlane $1 $3 $2 $4>', $spDiagramOutput);
            $spDiagramOutput = preg_replace('/isExpanded="true"/', '', $spDiagramOutput, 1);


            // Определяем размеры и позицию нашего процесса (его рамки)
            preg_match('/(?:\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*height="(\w+)")/', $spDiagramOutput, $spHeight);
            preg_match('/(?:\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*width="(\w+)")/', $spDiagramOutput, $spWidth);
            preg_match('/(?:\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*x="(\w+)")/', $spDiagramOutput, $spX);
            preg_match('/(?:\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*y="(\w+)")/', $spDiagramOutput, $spY);
//            echo "H: ".$spHeight[1]." W: ".$spWidth[1]." X: ".$spX[1]." Y: ".$spY[1];

            // Удаляем лишние строки из диаграммы
            $spDiagramOutput = preg_replace('/ *\<dc\:Bounds *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)* \/?\>[\n ]*\<bpmndi\:BPMNLabel *\/>[\n ]*\<\/bpmndi\:BPMNShape\>\n/', '', $spDiagramOutput, 1);

            // Ищем все группы в диаграмме
            $allGroupsInDiagramArr = array();
            foreach($groupsIDs as $thisGroupID){
                preg_match('/([\t\n ]*\<bpmndi\:BPMNShape *(?:[ \w\.\:\-]+\=\"[^\"]+\" *)*bpmnElement=\"'.$thisGroupID.'\" *(?:[ \:\w\.\-]+\=\"[^\"]+\" *)*\>(?:[\t\n ]*\<(?!\/bpmndi:BPMNShape)[\:\w \-\"\/\= \.]+\>[\t\n ]*)+\<\/bpmndi:BPMNShape\>[\t\n ]*)/', $spDiagramOutput, $groupsMatches);
                $allGroupsInDiagramArr[] = $groupsMatches[1];
            }
            unset($thisGroupID, $groupsMatches);

            // Удаляем все группы, которые по размеру больше, чем наш (под)процесс (графически) или которые выступают за границы подпроцесса
            foreach ($allGroupsInDiagramArr as &$thisGroup){

                preg_match('/\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*width="(\w+)"/', $thisGroup, $thisWidth);

                if ($thisWidth[1] >= $spWidth[1]) {

                    groupDelete($thisGroup,$spDiagramOutput, $allGroupsCatsBlocksStr, $allGroupsStr);
                    continue;
                }
                preg_match('/\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*height="(\w+)"/', $thisGroup, $thisHeight);
                if ($thisHeight[1] >= $spHeight[1]) {

                    groupDelete($thisGroup,$spDiagramOutput, $allGroupsCatsBlocksStr, $allGroupsStr);
                    continue;
                }
                preg_match('/\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*x="(\w+)"/', $thisGroup, $thisX);

                if ($thisX[1] <= $spX[1] or $thisX[1] >= intval($spX[1]) + intval($spWidth[1])) {
                    groupDelete($thisGroup,$spDiagramOutput, $allGroupsCatsBlocksStr, $allGroupsStr);
                    continue;
                }
                preg_match('/\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*y="(\w+)"/', $thisGroup, $thisY);

                if ($thisY[1] <= $spY[1] or $thisY[1] >= intval($spY[1]) + intval($spHeight[1])) {


                    groupDelete($thisGroup,$spDiagramOutput, $allGroupsCatsBlocksStr, $allGroupsStr);
                }
            } // foreach
            unset($thisGroup, $thisWidth, $thisHeight, $groupsToRemove, $allGroupsInDiagramArr);


        }
        else{// Обработка, если подпроцесс в collapsed состоянии

            // Ищем и обрабатываем категории групп в подпроцессах данного процесса
            preg_match_all('/group *(?:[ \w\.\-]+\=\"[^\"]+\" *)*categoryValueRef=\"([\w\-\.]+)\"/', $subprocess, $subprocessesCatGroupsIDs );

            foreach($subprocessesCatGroupsIDs[1] as &$thisGroupID){
                preg_match('/<bpmn:category *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>[\n ]*<bpmn:categoryValue *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id="'.$thisGroupID.'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/>[\n ]*<\/bpmn:category>/', $bpmnXML, $spCatGroupsArr );
                $spCatGroups .= "\n".$spCatGroupsArr[0];
            }
            unset($spCatGroupsArr);

            // Сохраняем диаграмму подпроцесса
            preg_match('/(\<bpmndi:BPMNDiagram *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>[\n ]*\<bpmndi:BPMNPlane *(?:[ \w\.\-]+\=\"[^\"]+\" *)*bpmnElement=\"'.$pid.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>(?:(?!\<\/bpmndi:BPMNDiagram)(?:[ \n]*\<(?:[ \/]*[\w\:\-\.]+ *(?:[\w\:\-\.]+\=\"[^\"]+\" *)*)[ \/]*\>[\n ]*)[\w\-]*)*[\n ]*<\/bpmndi:BPMNDiagram\>)/', $bpmnXML, $spDiagram );
            $spDiagramOutput = $spDiagram[1];
        }

        // Конвертируем подпроцесс в процесс
        $subprocess = preg_replace('/(\<bpmn:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id="'.$pid.'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>)/', '<bpmn:process id="'.$pid.'" isExecutable="true">', $subprocess);


        // Сохраяем id всех подпроцессов внутри этого нового процесса для того, чтобы собрать для них диаграммы
        preg_match_all('/(?=\<bpmn\:subProcess *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=\"([\w\.\-]+)\")/', $subprocess, $innerSubprocessesIDs );

        // Сохраняем все диаграммы подпроцессов данного нового процесса
        $innerSPDiagrams = "";
        foreach($innerSubprocessesIDs[1] as &$spID){
            preg_match('/(\<bpmndi:BPMNDiagram *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>[\n ]*\<bpmndi:BPMNPlane *(?:[ \w\.\-]+\=\"[^\"]+\" *)*bpmnElement=\"'.$spID.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>(?:(?!\<\/bpmndi:BPMNDiagram)(?:[ \n]*\<(?:[ \/]*[\w\:\-\.]+ *(?:[\w\:\-\.]+\=\"[^\"]+\" *)*)[ \/]*\>[\n ]*)[\w\-]*)*[\n ]*<\/bpmndi:BPMNDiagram>)/', $bpmnXML, $spInnerDiagramArr );
            $innerSPDiagrams .= $spInnerDiagramArr[1];
        }
        unset($spInnerDiagramArr);









        // Удаляем ссылки на группы, которые не существуют внутри данного процесса и его подпроцессов
        foreach ($groupsIDs as &$thisGroupID){

            // Если точно такая же группа есть внутри подпроцесса данного процесса - удаляем её из корня процесса (дубликат)
            if (preg_match('/([\n ]*\<bpmn:group *(?:[ \w\.\-]+\=\"[^\"]+\" *)* *id=\"'.$thisGroupID.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)* *\/?\>[\n ]*)/', $subprocess)){
                $allGroupsStr = preg_replace('/([\n ]*\<bpmn:group *(?:[ \w\.\-]+\=\"[^\"]+\" *)* *id=\"'.$thisGroupID.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)* *\/?\>[\n ]*)/', '', $allGroupsStr);
                continue;
            }
            // Иначе, если НЕТ ни в одной из диаграмм - удаляем
            else if (!preg_match('/bpmnElement\=\"'.$thisGroupID.'\"/', $spDiagramOutput.$innerSPDiagrams)){
                $allGroupsStr = preg_replace('/([\n ]*\<bpmn:group *(?:[ \w\.\-]+\=\"[^\"]+\" *)* *id=\"'.$thisGroupID.'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)* *\/?\>[\n ]*)/', '', $allGroupsStr);
            }
        }

        unset($thisGroupID);






        // Удаляем категории ненужных групп
        // Сохраняем все ID категорий
        preg_match_all('/ *<bpmn:category id="[\w\-\.]+">[\n ]*<bpmn:categoryValue id="([\w\-\.]+)" value="[^\"]+" \/>[\n ]*<\/bpmn:category>/', $allGroupsCatsBlocksStr, $allCatsIDsArr);
        foreach ($allCatsIDsArr[1] as &$thisCatID){
            if (!preg_match('/categoryValueRef=\"'.$thisCatID.'\"/', $subprocess)){
                $allGroupsCatsBlocksStr = preg_replace('/ *<bpmn:category id="[\w\-\.]+">[\n ]*<bpmn:categoryValue id="'.$thisCatID.'" value="[^\"]+" \/>[\n ]*<\/bpmn:category>/', '', $allGroupsCatsBlocksStr);
            }
        }
        unset($thisCatID, $allCatsIDsArr);

        // Сохраняем группы в корне процесса
        $subprocess = preg_replace('/  \<\/bpmn:subProcess\>$/', $allGroupsStr."\n  </bpmn:process>", $subprocess);

        // Удаляем связи с внешним (под)процессом
        $subprocess = preg_replace('/(?<=<bpmn\:process id=\"'.$pid.'\" isExecutable=\"true\">)([ \n]*\<bpmn:incoming\>[\w\-\.]*\<\/bpmn:incoming\>)(([ \n]*\<bpmn:incoming\>[\w\-\.]*\<\/bpmn:incoming\>)|([ \n]*\<bpmn:outgoing\>[\w\-\.]*\<\/bpmn:outgoing\>))*/', '', $subprocess);



        // Удаляем лишние элементы, которых нет в текущем процессе, но были в родителе (линии, например)
        // Собираем всё вместе ДО диаграммы
        $beforeDiagDataStr = $subprocess."\n".$spCatGroups."\n".$allGroupsCatsBlocksStr;

        unset($subprocess, $spCatGroups, $allGroupsCatsBlocksStr);

        // Сохраняем все bpmnElement элементов в диаграмме
        preg_match_all('/bpmnElement\=\"([\w\.\-]+)\"/', $spDiagramOutput, $variousBPMNElementsArr);
        foreach ($variousBPMNElementsArr[1] as &$thisElemID){

            // Если нет такого ID в документе (вне диаграммы) - удаляем весь элемент
            if(!preg_match('/id=\"'.$thisElemID.'\"/', $beforeDiagDataStr)){

                $checker = 1;
                $tempDataStr = "";

                foreach (explode("\n", $spDiagramOutput) as &$thisLine){

                    if ($checker == 1) {
                        if (preg_match('/bpmnElement="' . $thisElemID . '"/', $thisLine, $tempDataArr)) {
                            $checker = 2;
                            $tempDataStr .= $thisLine."\n";
                            continue;
                        }
                    }
                    else if(preg_match('/bpmnElement="[\w\.\-]+"/', $thisLine) or preg_match('/\<\/bpmndi\:BPMNPlane\>/', $thisLine)){
                        break;
                    }
                    else {
                        $tempDataStr .= $thisLine."\n";
                    }
                }

                $spDiagramOutput = str_replace($tempDataStr, "", $spDiagramOutput);

                unset($thisLine, $checker, $tempDataStr);
            }
        }

        unset($thisElemID, $tempDataArr, $variousBPMNElementsArr);

        //Передвигаем все элементы к точкам x=100 y=100
        //Определяем первый элемент (положение X/Y)
        preg_match('/\<dc\:Bounds *(?:[ \w\.\:\-]+\=\"[^\"]+\" *)*x=\"([\.\d]+)\"/', $spDiagramOutput, $spDiagX);
        preg_match('/\<dc\:Bounds *(?:[ \w\.\:\-]+\=\"[^\"]+\" *)*y=\"([\.\d]+)\"/', $spDiagramOutput, $spDiagY);

        // Высчитываем офсет от исходных значений
        $offsetX = intval($spDiagX[1]) - 20;
        $offsetY = intval($spDiagY[1]) - 80;

        // Заменяем все значения
        $spDiagramOutputNew = "";
        foreach (explode("\n", $spDiagramOutput) as &$thisLine){
            if (preg_match('/x=\"([\d\.]+)\"/', $thisLine, $thisX) and preg_match('/y=\"([\d\.]+)\"/', $thisLine, $thisY)){
                $thisLine = preg_replace('/x=\"[\d\.]+\"/', 'x="'.intval($thisX[1]) - $offsetX.'"', $thisLine);
                $thisLine = preg_replace('/y=\"[\d\.]+\"/', 'y="'.intval($thisY[1]) - $offsetY.'"', $thisLine);
                unset($thisX, $thisY);
            }
            $spDiagramOutputNew .= $thisLine."\n";
        }
        unset($spDiagramOutput, $thisLine);

        // Удаляем всё остальное между bpmn:definitions тегами и заменяем новым процессом и диаграммой к нему
        $bpmnXML = explode("\n", $bpmnXML)[0]."\n".explode("\n", $bpmnXML)[1]."\n".$beforeDiagDataStr."\n".$spDiagramOutputNew."\n".$innerSPDiagrams."\n"."</bpmn:definitions>\n";



    }
    // процесс
    else if (preg_match('/<bpmn:process id=\"'.$pid.'\"/', $bpmnXML )){
        // процесc

        // Удаляем другие процессы
        $bpmnXML = preg_replace('/(\<bpmn\:process *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=(?!\"'.$pid.'\")\"[\w\-\.]+\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>(?:[\t\n ]*((?!\<\/bpmn\:process\>)(?:(?:\<\/?[\:\w \-\"\/\=]+ *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/?\>[\n\ ]*)([^<]+\<\/[\:\w \-\"\/\=]+ *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/?\>[\n\ ]*)?))*)<\/bpmn:process>)/', '', $bpmnXML);

        // Сохраняем все категории групп
        $allGroupsCatsBlocksStr = "";
        preg_match_all('/ *<bpmn:category id="[\w\-\.]+">[\n ]*<bpmn:categoryValue id="[\w\-\.]+" value="[^\"]+" \/>[\n ]*<\/bpmn:category>/', $bpmnXML, $allGroupsCatsBlocksArr );
        foreach($allGroupsCatsBlocksArr[0] as &$thisCatGr){
            $allGroupsCatsBlocksStr .= $thisCatGr;
        }
        unset($thisCatGr, $allGroupsCatsBlocksArr);

        // Есть ли collaboration (Pool/Participant)
        if (preg_match('/<bpmn:collaboration/', $bpmnXML )){

            // Есть ли группы
            if (preg_match('/<bpmn:group/', $bpmnXML )){

                // Находим id пула для целевого процесса
                preg_match('/<bpmn:participant *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=\"([\w\-\.]+)\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*processRef=\"'.$pid.'\"/', $bpmnXML, $participantIdMatches );

                // Проверяем - есть ли такой пул
                if (!$participantIdMatches[1]){
                    header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
                    echo "Wrong XML format: not collaboration found for the provided PID";
                    exit;
                }

                // Сохраняем все группы внутри нашего пула в переменную для дальнейшей фильтрации
                // Получаем ID нашей диаграммы
//                preg_match('/(?:\<bpmndi\:BPMNDiagram *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=\"([\w\-\.]+)\"\>[\n ]*((?!\<\/bpmndi:BPMNDiagram)(?:\<[\:\w \-\"\/\= ]+\>)[\n ]*)*\<bpmndi\:BPMNShape *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=\"Participant_[\w\-\.]+\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*bpmnElement=\"'.$participantIdMatches[1].'\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>(?:[\n ]*(\<(?!\/bpmndi:BPMNDiagram)[\:\w \-\"\/\= ]+\>[\n ]*)*))/', $bpmnXML, $diagramID);
                preg_match('/(?:\<bpmndi\:BPMNDiagram *(?:[ \w\.\:\-]+\=\"[^\"]+\" *)*id=\"([\w\-\.]+)\"\>(?:[\n .\<\>\=\w\:\/\-\"\#]*bpmnElement=\"'.$participantIdMatches[1].'\"))/', $bpmnXML, $diagramID);

                // Сохраняем весю диаграмму с нашим процессом в переменную для дальнейшей работы с группами
                preg_match('/((?:\<bpmndi\:BPMNDiagram id=\"'.$diagramID[1].'\"\>)(?:[\t\n ]*\<(?!\/bpmndi\:BPMNDiagram)[\:\w \-\"\/\= \.]+ *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*\>[\n\t ]*)*\<\/bpmndi\:BPMNDiagram\>)/', $bpmnXML, $diagMatches);

                // Удаляем из блока collaboration все пулы с другими процессами и все messageFlow (стрелки из-/во-вне)
                $bpmnXML = preg_replace('/(\<bpmn:participant *(?:[ \w\.\-]+\=\"[^\"]+\" *)*id=\"[\w\-\.]+\" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*processRef=(?!\"'.$pid.'\")(?:\"[\w\.\-]+\") *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/?\>\n)/', '', $bpmnXML);
                $bpmnXML = preg_replace('/([\n\t]*\<bpmn:messageFlow *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\/?\>[\n\t]*)/', '', $bpmnXML);

                // Удаляем все bpmnElement="Participant_ и всё, что после них, которые не относятся к этому процессу
                $checker = 1;
                $tempDataToRemoveStr = "";

                foreach(explode("\n", $diagMatches[0]) as &$thisLine){

                    if ($checker == 1){
                        if (preg_match('/(\<bpmndi\:BPMNShape *(?:[ \w\.\:\-]+\=\"[^\"]+\" *)*id=\"Participant_[\w\-\.]+\" *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*bpmnElement=\"(?!'.$participantIdMatches[1].')[\w\.\-]+\" *(?:[ \w\.\:\-]+\=\"[^\"]+\" *)*\>)/', $thisLine)){
                            $checker = 2;
                            $tempDataToRemoveStr .= $thisLine."\n";
                            continue;
                        }
                    }
                    else {
                        if (preg_match('/<bpmndi\:BPMNShape *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*id=\"Participant_[\w\-\.]+\" *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*\/?\>/', $thisLine)){
                            break;
                        }
                        else if(preg_match('/\<bpmndi\:BPMNShape *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*id=\"Group_[\w\-\.]+\" *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*\>/', $thisLine)){
                            break;
                        }
                        else if(preg_match('/\<\/bpmndi\:BPMNPlane\>/', $thisLine)){
                            break;
                        }

                        $tempDataToRemoveStr .= $thisLine."\n";
                    }
                }

                $diagMatches[0] = str_replace($tempDataToRemoveStr, "", $diagMatches[0]);

                //Передвигаем все элементы к точкам x=100 y=100
                //Определяем первый элемент (положение X/Y)
                preg_match('/\<dc\:Bounds *(?:[ \w\.\:\-]+\=\"[^\"]+\" *)*x=\"([\.\d]+)\"/', $diagMatches[0], $spDiagX);
                preg_match('/\<dc\:Bounds *(?:[ \w\.\:\-]+\=\"[^\"]+\" *)*y=\"([\.\d]+)\"/', $diagMatches[0], $spDiagY);

                // Высчитываем офсет от исходных значений
                $offsetX = intval($spDiagX[1]) - 20;
                $offsetY = intval($spDiagY[1]) - 80;

                // Заменяем все значения
                $mainDiagramOutputNew = "";
                foreach (explode("\n", $diagMatches[0]) as &$thisLine){
                    if (preg_match('/x=\"([\d\.]+)\"/', $thisLine, $thisX) and preg_match('/y=\"([\d\.]+)\"/', $thisLine, $thisY)){
                        $thisLine = preg_replace('/x=\"[\d\.]+\"/', 'x="'.intval($thisX[1]) - $offsetX.'"', $thisLine);
                        $thisLine = preg_replace('/y=\"[\d\.]+\"/', 'y="'.intval($thisY[1]) - $offsetY.'"', $thisLine);
                        unset($thisX, $thisY);
                    }
                    $mainDiagramOutputNew .= $thisLine."\n";
                }
                unset($diagMatches, $thisLine);
//                echo $bpmnXML;

                $bpmnXML = preg_replace('/((?:\<bpmndi\:BPMNDiagram id=\"'.$diagramID[1].'\"\>)(?:[\t\n ]*\<(?!\/bpmndi\:BPMNDiagram)[\:\w \-\"\/\= \.]+ *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*\>[\n\t ]*)*\<\/bpmndi\:BPMNDiagram\>)/', $mainDiagramOutputNew, $bpmnXML);
                unset($thisLine, $tempDataToRemoveStr);
//                echo "\n\n\n ---------------- \n\n\n";
//                echo $bpmnXML;
//                exit;


                // Копируем все группы внутри нашего пула (внутри диаграммы) и сохраняем их в видем массива массивов (каждая группа отедлным элементом внутри первого элемента главного массива)
                preg_match_all('/([\t\n ]*\<bpmndi\:BPMNShape *(?:[ \w\:\.\-]+\=\"[^\"]+\" *)*id=\"Group_[\w\-\.]+\" *(?:[ \:\w\.\-]+\=\"[^\"]+\" *)*\>(?:[\t\n ]*\<(?!\/bpmndi:BPMNShape)[\:\w \-\"\/\= \.]+\>[\t\n ]*)+\<\/bpmndi:BPMNShape\>[\t\n ]*)/', $mainDiagramOutputNew, $groupsMatches);



                //TODO: удалять диаграммы неиспользуемых процессов

                // Сохраняем наш процесс с самого начала страницы (для поиска даже среди Collaborations
                preg_match('/(?:([\s\S]+?)(?:(?=<bpmndi:BPMNDiagram)[\s\S]+))/', $bpmnXML, $ourProcessArr);

                // Сохраняем ID (под)процессов, к которым созданы диаграммы
                preg_match_all('/<bpmndi:BPMNPlane id="[\w\-\.]+" bpmnElement="([\.\w\-]+)">/', $bpmnXML, $allDieagsIDs);

                // Удаляем все диаграммы, которые не связанны с нашим процессом и его подпроцессами
                foreach($allDieagsIDs[1] as $thisID){
                    if(!preg_match('/id=\"'.$thisID.'\"/', $ourProcessArr[1])){
                        $bpmnXML = preg_replace('/(\<bpmndi\:BPMNDiagram *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)*\>[\n ]*\<bpmndi\:BPMNPlane *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)*bpmnElement=\"'.$thisID.'\">(?:[\n ]*\<\/?(?!bpmndi\:BPMNDiagram)[\w\-\.\:]+ *(?:[ \w\.\-\:]+\=\"[^\"]+\" *)*\/?\>(?:[^\<]+\<\/?(?!bpmndi\:BPMNDiagram)[\w\-\.\:]+\/?\>)?[\n ]*)+\<\/bpmndi\:BPMNDiagram\>)/', "", $bpmnXML);
                    }

                }
                unset($thisID, $allDieagsIDs);

                // Определяем размеры нашего пула
                preg_match('/(?:<bpmndi:BPMNShape *(?:[ \w\.\-]+\=\"[^\"]+\" *)*bpmnElement="'.$participantIdMatches[1].'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>[\n\t ]*)\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*width="(\w+)"/', $bpmnXML, $participantWidth);
                preg_match('/(?:<bpmndi:BPMNShape *(?:[ \w\.\-]+\=\"[^\"]+\" *)*bpmnElement="'.$participantIdMatches[1].'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>[\n\t ]*)\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*height="(\w+)"/', $bpmnXML, $participantHeight);
                preg_match('/(?:<bpmndi:BPMNShape *(?:[ \w\.\-]+\=\"[^\"]+\" *)*bpmnElement="'.$participantIdMatches[1].'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>[\n\t ]*)\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*x="(\w+)"/', $bpmnXML, $participantX);
                preg_match('/(?:<bpmndi:BPMNShape *(?:[ \w\.\-]+\=\"[^\"]+\" *)*bpmnElement="'.$participantIdMatches[1].'" *(?:[ \w\.\-]+\=\"[^\"]+\" *)*\>[\n\t ]*)\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*y="(\w+)"/', $bpmnXML, $participantY);

                // Удаляем все группы, которые по размеру больше, чем наш пул или которые выступают за границы пула
                foreach ($groupsMatches[0] as &$thisGroup){

//echo $thisGroup;

                    preg_match('/\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*width="(\w+)"/', $thisGroup, $thisWidth);

                    if ($thisWidth[1] >= $participantWidth[1]) {
//echo 1;
                        groupDelete($thisGroup, $bpmnXML, $allGroupsCatsBlocksStr);
                        continue;
                    }
                    preg_match('/\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*height="(\w+)"/', $thisGroup, $thisHeight);
                    if ($thisHeight[1] >= $participantHeight[1]) {
//echo 2;
                        groupDelete($thisGroup, $bpmnXML, $allGroupsCatsBlocksStr);
                        continue;
                    }
                    preg_match('/\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*x="(\w+)"/', $thisGroup, $thisX);
                    if ($thisX[1] <= $participantX[1] or $thisX[1] >= intval($participantX[1]) + intval($participantWidth[1])) {
//echo 3;
                        groupDelete($thisGroup, $bpmnXML, $allGroupsCatsBlocksStr);
                        continue;
                    }
                    preg_match('/\<dc\:Bounds *(?:[ \w\.\-]+\=\"[^\"]+\" *)*y="(\w+)"/', $thisGroup, $thisY);
                    if ($thisY[1] <= $participantY[1] or $thisY[1] >= intval($participantY[1]) + intval($participantHeight[1])) {
//echo 4;
                        groupDelete($thisGroup, $bpmnXML, $allGroupsCatsBlocksStr);
                    }
                } // foreach
                unset($thisGroup, $thisWidth, $thisHeight, $groupsToRemove);
//exit;

                // Удаляем лишние элементы, которых нет в текущем процессе, но были в родителе (линии, например)
                // Сохраняем все bpmnElement элементов в диаграмме
                preg_match_all('/bpmnElement\=\"([\w\.\-]+)\"/', $mainDiagramOutputNew, $variousBPMNElementsArr);

                foreach ($variousBPMNElementsArr[1] as &$thisElemID){

                    // Если нет такого ID в документе (вне диаграммы) - удаляем весь элемент
                    if(!preg_match('/id=\"'.$thisElemID.'\"/', $ourProcessArr[1])){

                        $checker = 1;
                        $tempDataStr = "";
                        foreach (explode("\n", $bpmnXML) as &$thisLine){

                            if ($checker == 1) {
                                if (preg_match('/bpmnElement=\"' . $thisElemID . '\"/', $thisLine, $tempDataArr)) {
                                    $checker = 2;
                                    $tempDataStr .= $thisLine."\n";
                                }
                            }
                            else if(preg_match('/bpmnElement="[\w\.\-]+"/', $thisLine) or preg_match('/\<\/bpmndi\:BPMNPlane\>/', $thisLine)){
                                break;
                            }
                            else {
                                $tempDataStr .= $thisLine."\n";
                            }
                        }

                        $bpmnXML = str_replace($tempDataStr, "", $bpmnXML);

                        unset($thisLine, $checker, $tempDataStr);
                    }
                }

                unset($thisElemID, $tempDataArr, $variousBPMNElementsArr);


                //TODO: ПРОВЕРИТЬ!!! НИЖЕ заблокировал, потому, что режет всё!
                // Перезаписываем категории групп обратно в общий bpmn
//                $bpmnXML = preg_replace('/( *<bpmn:category id="[\w\-\.]+">[\n ]*<bpmn:categoryValue id="[\w\-\.]+" value="[^\"]+" \/>[\n ]*<\/bpmn:category>[\n]*)+/', $allGroupsCatsBlocks, $bpmnXML);
            }
        }
    }
}
else {
    //...

}
//echo $bpmnXML;
//exit;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"/>
    <title>BPMN viewer</title>
    <!-- required viewer styles -->
    <!-- needed for this example only -->
    <script src="js/jquery.js"></script>
    <link rel="stylesheet" href="css/bpmn-js.css">
    <link rel="stylesheet" href="css/jquery-ui.css">
    <!--    <link rel="stylesheet" href="/css/fontawesome/css/fontawesome.css"/>
        <link rel="stylesheet" href="/css/fontawesome/css/solid.css"/> -->

    <!-- viewer distro (with pan and zoom) -->
    <script src="js/bpmn-navigated-viewer.development.js"></script>
    <script src="js/jquery-ui.js"></script>

    <style>
        #canvas {
            border: 1px solid;
            position: relative;
            border: 1px solid;
            background-color: #FFF;
            height: 95vh;
            width: 100%;
            padding: 0;
            margin: 0;
        }
        #loadingDiv {
            width: 100%;
            height: 100%;
            display: inline-block;
            padding: 10px;
            top: 50%;
            left: 50%;
            transform: translate(-50%,-50%);
            position: absolute;
            background-image: url('/imgs/canvas-loader.gif');
            background-size: 150px 150px;
            background-repeat: no-repeat;
            background-position: center;
            background-color: #f6f6f6;
            z-index: 10;
        }
        .bpmn-breadcrumbs-current-pid {
            color: black;
            text-decoration: none;
        }
        #BPMNBreadCrumbs { /* div */
            font-family: system-ui;
            padding-top: 5px;
            padding-right: 0px;
            padding-bottom: 4px;
            padding-left: 5px;
            position: absolute;
            top: 0px;
            z-index: 10;
        }
        .bpmn-breadcrumbs{
            color: teal;
        }
        .bpmn-breadcrumbs-home {
            color: royalblue;
            font-weight: bold;
        }
        .bpmn-breadcrumbs-cursor {
            color: #333;
            font-size: 0.8em;
        }
        #bpmn-container {
            position: relative;
            width: 100%;
            height: 100%;
        }
        #tooltip{
            position: absolute;
            top: 100px;
            left: 100px;
            z-index: 999999;
            display: none;
            background-color: #FFE300;
            width: fit-content;
            height: fit-content;
            max-width: 250px;
            font-size: 0.65em;
            font-family: Tahoma;
            padding: 2px 5px 2px 5px;
            border-radius: 3px;
        }
    </style>

</head>
<body>
<div id="tooltip">TOOLTIP</div>
<div id="bpmn-container">

    <?php
    if($_GET["pid"]) {
        echo '<div id="BPMNBreadCrumbs"></div>';
    }
    ?>
    <div id="canvas">

        <div id="loadingDiv"></div>
    </div>
</div>

<script>

    <?php
    echo 'var origUrl = window.location.href;';
    echo 'var currentHref = origUrl.replace(/pid\=[\w\-\.]+$/, "");';
    if($parrentLinksArr[0]) {

        $checker = 1;
        foreach (array_reverse($parrentLinksArr) as &$thisParLink) {
            if ($checker == 1) {
                echo '$("#BPMNBreadCrumbs").append("<a href=\"" + currentHref + "pid=' . $thisParLink . '\" class=\"bpmn-breadcrumbs\">' . $thisParLink . '</a>");';
                $checker = 2;
            } else {
                echo '$("#BPMNBreadCrumbs").append("<span class=\"bpmn-breadcrumbs-cursor\">&nbsp;&nbsp;&gt;&nbsp;&nbsp;</span><a href=\"" + currentHref + "pid=' . $thisParLink . '\" class=\"bpmn-breadcrumbs\">' . $thisParLink . '</a>");';
            }
        }
        if ($checker == 2) {
            echo '$("#BPMNBreadCrumbs").append("<span class=\"bpmn-breadcrumbs-cursor\">&nbsp;&nbsp;&gt;&nbsp;&nbsp;</span><span class=\"bpmn-breadcrumbs-current-pid\">' . $_GET["pid"] . '</span>");';
        } else {
            echo '$("#BPMNBreadCrumbs").append("<span class=\"bpmn-breadcrumbs-current-pid\">' . $_GET["pid"] . '</span>");';
        }
    }
    if ($_GET["pid"] and $parrentLinksArr[0]) {
        echo '$("#BPMNBreadCrumbs").prepend("<a href=\"" + currentHref + "pid=\" class=\"bpmn-breadcrumbs-home\">Общая схема</a><span class=\"bpmn-breadcrumbs-cursor\">&nbsp;&nbsp;&gt;&nbsp;&nbsp;</span>");';
    }
    else if ($_GET["pid"]){
        echo '$("#BPMNBreadCrumbs").append("<span class=\"bpmn-breadcrumbs-cursor\">&nbsp;&nbsp;&gt;&nbsp;&nbsp;</span><span class=\"bpmn-breadcrumbs-current-pid\">' . $_GET["pid"] . '</span>");';
        echo '$("#BPMNBreadCrumbs").prepend("<a href=\"" + currentHref + "pid=\" class=\"bpmn-breadcrumbs-home\">Общая схема</a>");';
    }
    ?>


    // viewer instance
    var bpmnViewer = new BpmnJS({
        container: '#canvas'
    });


    /**
     * Open diagram in our viewer instance.
     *
     * @param {String} bpmnXML diagram to display
     */
    async function openDiagram(bpmnXML) {

        // import diagram
        try {

            await bpmnViewer.importXML(bpmnXML);

            $("#loadingDiv").animate({
                opacity: "toggle"
            }, 1000, "linear");

            // access viewer components
            var canvas = bpmnViewer.get('canvas');
            // var overlays = bpmnViewer.get('overlays');

            // zoom to fit full viewport
            canvas.zoom('fit-viewport');
            //canvas.zoom(0.7,{x: 0, y: 30});

            // attach an overlay to a node
            // overlays.add('SCAN_OK', 'note', {
            //   position: {
            //     bottom: 0,
            //     right: 0
            //   },
            //   html: '<div class="diagram-note">Mixed up the labels?</div>'
            // });

            // add marker
            // canvas.addMarker('SCAN_OK', 'needs-discussion');
        } catch (err) {

            console.error('could not import BPMN 2.0 diagram', err);
        }
    }


    // load external diagram file via AJAX and open it
    openDiagram(`<?php echo $bpmnXML ?>`);
</script>

<script>
    $(".bjs-drilldown").on("click", function () {
        console.log("Done!");
        $(".djs-shape").mouseenter(function(e){
            var thisShape = $(this);
            if ($(this).attr("data-documentation") !== undefined && $(this).attr("data-tt-x_pos") !== undefined && $(this).attr("data-tt-y_pos") !== undefined) {
                $("#tooltip").css({left: e.pageX, top: e.pageY, display: 'block'});
                // var thisDoc = thisShape.attr("data-documentation").replaceAll("nnn", '<br />');
                $("#tooltip").html(thisShape.attr("data-documentation"));
                console.log($(this))
            }
            thisShape.mouseleave(function(){
                var thisShape = $(this);
                if ($(this).attr("data-documentation") !== undefined && $(this).attr("data-tt-x_pos") !== undefined && $(this).attr("data-tt-y_pos") !== undefined) {
                    $("#tooltip").css({display: 'none'});
                }
            });
        });
    });
    $(".bjs-crumb").on("click", function () {
        console.log("Done!");
        $(".djs-shape").mouseenter(function(e){
            var thisShape = $(this);
            if ($(this).attr("data-documentation") !== undefined && $(this).attr("data-tt-x_pos") !== undefined && $(this).attr("data-tt-y_pos") !== undefined) {
                $("#tooltip").css({left: e.pageX, top: e.pageY, display: 'block'});
                // var thisDoc = thisShape.attr("data-documentation").replaceAll("nnn", '<br />');
                $("#tooltip").html(thisShape.attr("data-documentation"));
                console.log($(this))
            }
            thisShape.mouseleave(function(){
                var thisShape = $(this);
                if ($(this).attr("data-documentation") !== undefined && $(this).attr("data-tt-x_pos") !== undefined && $(this).attr("data-tt-y_pos") !== undefined) {
                    $("#tooltip").css({display: 'none'});
                }
            });
        });
    });
    $(".djs-shape").mouseenter(function(e){
        var thisShape = $(this);
        if ($(this).attr("data-documentation") !== undefined && $(this).attr("data-tt-x_pos") !== undefined && $(this).attr("data-tt-y_pos") !== undefined) {
            $("#tooltip").css({left: e.pageX, top: e.pageY, display: 'block'});
            // var thisDoc = thisShape.attr("data-documentation").replaceAll("nnn", '<br />');
            $("#tooltip").html(thisShape.attr("data-documentation"));
            console.log($(this))
        }
        thisShape.mouseleave(function(){
            var thisShape = $(this);
            if ($(this).attr("data-documentation") !== undefined && $(this).attr("data-tt-x_pos") !== undefined && $(this).attr("data-tt-y_pos") !== undefined) {
                $("#tooltip").css({display: 'none'});
            }
        });
    });

</script>
</body>
</html>
