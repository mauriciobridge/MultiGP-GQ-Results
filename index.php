<?php
// Establecer codificación UTF-8
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

class MultiGPResultsFilter {
    private $apiUrl = 'https://www.multigp.com/MultiGP/views/viewZipperSeasonResults2025.php';
    private $results = [];
    private $countries = [];
    private $chapters = [];
    
    public function __construct() {
        $this->fetchResults();
        $this->calculateNationalRankings();
        $this->extractFilters();
    }
    
    /**
     * Obtiene los resultados de la API de MultiGP
     */
    private function fetchResults() {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            throw new Exception('Error de cURL: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Error HTTP: $httpCode");
        }
        
        $this->parseMultiGPResponse($response);
    }
    
    /**
     * Parsea la respuesta HTML específica de MultiGP
     */
    private function parseMultiGPResponse($html) {
        // Convertir a UTF-8 si no lo está
        $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        // Cargar HTML con codificación UTF-8
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Buscar la tabla específica con id="topPilotTable"
        $table = $xpath->query('//table[@id="topPilotTable"]')->item(0);
        
        if (!$table) {
            throw new Exception('No se encontró la tabla de resultados');
        }
        
        $results = [];
        $rows = $xpath->query('.//tbody/tr', $table);
        
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);
            
            if ($cells->length >= 4) {
                $rank = trim($cells->item(0)->textContent);
                
                // Extraer información del piloto (celda 2)
                $pilotCell = $cells->item(1);
                $pilotInfo = $this->extractPilotInfo($pilotCell, $xpath);
                
                // Extraer información de la carrera (celda 3)
                $raceCell = $cells->item(2);
                $raceInfo = $this->extractRaceInfo($raceCell, $xpath);
                
                // Capítulo (celda 4)
                $chapter = trim($cells->item(3)->textContent);
                
                $results[] = [
                    'rank' => intval($rank),
                    'national_rank' => 0, // Se calculará después
                    'pilot_name' => $pilotInfo['name'],
                    'pilot_nickname' => $pilotInfo['nickname'],
                    'country' => $pilotInfo['country'],
                    'country_code' => $pilotInfo['country_code'],
                    'race_info' => $raceInfo['info'],
                    'race_time' => $raceInfo['time'],
                    'race_time_formatted' => $raceInfo['time_formatted'],
                    'race_link' => $raceInfo['link'],
                    'chapter' => $chapter
                ];
            }
        }
        
        $this->results = $results;
    }
    
    /**
     * Calcula el ranking nacional para cada piloto
     */
    private function calculateNationalRankings() {
        // Agrupar pilotos por país
        $pilotsByCountry = [];
        
        foreach ($this->results as $result) {
            $country = $result['country_code'];
            if (!isset($pilotsByCountry[$country])) {
                $pilotsByCountry[$country] = [];
            }
            $pilotsByCountry[$country][] = $result;
        }
        
        // Calcular ranking nacional para cada país
        foreach ($pilotsByCountry as $country => $pilots) {
            // Ordenar por ranking mundial (ya viene ordenado, pero por seguridad)
            usort($pilots, function($a, $b) {
                return $a['rank'] - $b['rank'];
            });
            
            // Asignar ranking nacional
            for ($i = 0; $i < count($pilots); $i++) {
                $nationalRank = $i + 1;
                
                // Buscar el piloto en el array principal y actualizar su ranking nacional
                for ($j = 0; $j < count($this->results); $j++) {
                    if ($this->results[$j]['rank'] === $pilots[$i]['rank'] && 
                        $this->results[$j]['country_code'] === $country) {
                        $this->results[$j]['national_rank'] = $nationalRank;
                        break;
                    }
                }
            }
        }
    }
    
    /**
     * Extrae información del piloto de la celda
     */
    private function extractPilotInfo($pilotCell, $xpath) {
        $flagImg = $xpath->query('.//img[@class="country-flag"]', $pilotCell)->item(0);
        $countryCode = '';
        $country = '';
        
        if ($flagImg) {
            $countryCode = $flagImg->getAttribute('title');
            $flagSrc = $flagImg->getAttribute('src');
            // Extraer código de país de la URL de la bandera
            if (preg_match('/\/flags\/([a-z]{2})\.png/', $flagSrc, $matches)) {
                $countryCode = strtoupper($matches[1]);
            }
            $country = $this->getCountryName($countryCode);
        }
        
        // Extraer nombre completo del piloto
        $fullText = trim($pilotCell->textContent);
        
        // Buscar patrón: Nombre 'nickname' Apellido
        $name = '';
        $nickname = '';
        
        if (preg_match("/^(.+?)\s+'([^']+)'\s+(.+)$/", $fullText, $matches)) {
            $name = trim($matches[1] . ' ' . $matches[3]);
            $nickname = trim($matches[2]);
        } else {
            $name = $fullText;
        }
        
        return [
            'name' => $name,
            'nickname' => $nickname,
            'country' => $country,
            'country_code' => $countryCode
        ];
    }
    
    /**
     * Extrae información de la carrera
     */
    private function extractRaceInfo($raceCell, $xpath) {
        $link = $xpath->query('.//a', $raceCell)->item(0);
        $raceLink = '';
        
        if ($link) {
            $raceLink = $link->getAttribute('href');
        }
        
        $fullText = trim($raceCell->textContent);
        
        // Extraer tiempo (buscar patrones más complejos)
        $time = '';
        $timeFormatted = '';
        
        // Buscar patrones como: 1:23.456, 123.456, 1m23.456s, etc.
        if (preg_match('/(\d+):(\d+)\.(\d+)/', $fullText, $matches)) {
            // Formato minutos:segundos.milisegundos
            $minutes = intval($matches[1]);
            $seconds = intval($matches[2]);
            $milliseconds = $matches[3];
            $time = ($minutes * 60) + $seconds + ('0.' . $milliseconds);
            $timeFormatted = $matches[1] . ':' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '.' . $milliseconds;
        } elseif (preg_match('/(\d+)m\s*(\d+)\.(\d+)s/', $fullText, $matches)) {
            // Formato 1m 23.456s
            $minutes = intval($matches[1]);
            $seconds = intval($matches[2]);
            $milliseconds = $matches[3];
            $time = ($minutes * 60) + $seconds + ('0.' . $milliseconds);
            $timeFormatted = $minutes . ':' . str_pad($seconds, 2, '0', STR_PAD_LEFT) . '.' . $milliseconds;
        } elseif (preg_match('/(\d+)\.(\d+)/', $fullText, $matches)) {
            // Formato simple segundos.milisegundos
            $time = $matches[1] . '.' . $matches[2];
            $totalSeconds = floatval($time);
            if ($totalSeconds >= 60) {
                $minutes = floor($totalSeconds / 60);
                $seconds = $totalSeconds - ($minutes * 60);
                $timeFormatted = $minutes . ':' . number_format($seconds, 3, '.', '');
                // Asegurar formato correcto
                if (preg_match('/(\d+):(\d+)\.(\d+)/', $timeFormatted, $formatMatches)) {
                    $timeFormatted = $formatMatches[1] . ':' . str_pad($formatMatches[2], 2, '0', STR_PAD_LEFT) . '.' . $formatMatches[3];
                }
            } else {
                $timeFormatted = number_format($totalSeconds, 3, '.', '') . 's';
            }
        }
        
        return [
            'info' => $fullText,
            'time' => $time,
            'time_formatted' => $timeFormatted,
            'link' => $raceLink
        ];
    }
    
    /**
     * Convierte código de país a nombre
     */
    private function getCountryName($code) {
        $countries = [
            'US' => 'Estados Unidos',
            'KR' => 'Corea del Sur',
            'JP' => 'Japón',
            'BG' => 'Bulgaria',
            'LI' => 'Liechtenstein',
            'FR' => 'Francia',
            'PL' => 'Polonia',
            'CN' => 'China',
            'SE' => 'Suecia',
            'TR' => 'Turquía',
            'DE' => 'Alemania',
            'GB' => 'Reino Unido',
            'CA' => 'Canadá',
            'AU' => 'Australia',
            'NL' => 'Países Bajos',
            'BE' => 'Bélgica',
            'CH' => 'Suiza',
            'AT' => 'Austria',
            'IT' => 'Italia',
            'ES' => 'España',
            'CO' => 'Colombia',
            'BR' => 'Brasil',
            'MX' => 'México',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'PE' => 'Perú',
            'VE' => 'Venezuela',
            'EC' => 'Ecuador',
            'BO' => 'Bolivia',
            'UY' => 'Uruguay',
            'PY' => 'Paraguay',
            'CR' => 'Costa Rica',
            'PA' => 'Panamá',
            'GT' => 'Guatemala',
            'HN' => 'Honduras',
            'SV' => 'El Salvador',
            'NI' => 'Nicaragua',
            'DO' => 'República Dominicana',
            'CU' => 'Cuba',
            'PT' => 'Portugal',
            'RU' => 'Rusia',
            'IN' => 'India',
            'TH' => 'Tailandia',
            'MY' => 'Malasia',
            'SG' => 'Singapur',
            'PH' => 'Filipinas',
            'ID' => 'Indonesia',
            'VN' => 'Vietnam',
            'ZA' => 'Sudáfrica',
            'EG' => 'Egipto',
            'MA' => 'Marruecos',
            'NG' => 'Nigeria',
            'KE' => 'Kenia',
            'GH' => 'Ghana',
            'NO' => 'Noruega',
            'DK' => 'Dinamarca',
            'FI' => 'Finlandia',
            'IS' => 'Islandia',
            'IE' => 'Irlanda',
            'LU' => 'Luxemburgo',
            'MT' => 'Malta',
            'CY' => 'Chipre',
            'GR' => 'Grecia',
            'HR' => 'Croacia',
            'SI' => 'Eslovenia',
            'SK' => 'Eslovaquia',
            'CZ' => 'República Checa',
            'HU' => 'Hungría',
            'RO' => 'Rumania',
            'EE' => 'Estonia',
            'LV' => 'Letonia',
            'LT' => 'Lituania',
            'UA' => 'Ucrania',
            'BY' => 'Bielorrusia',
            'MD' => 'Moldavia',
            'RS' => 'Serbia',
            'BA' => 'Bosnia y Herzegovina',
            'ME' => 'Montenegro',
            'MK' => 'Macedonia del Norte',
            'AL' => 'Albania',
            'XK' => 'Kosovo',
            'IL' => 'Israel',
            'JO' => 'Jordania',
            'LB' => 'Líbano',
            'SY' => 'Siria',
            'IQ' => 'Irak',
            'IR' => 'Irán',
            'SA' => 'Arabia Saudí',
            'AE' => 'Emiratos Árabes Unidos',
            'QA' => 'Catar',
            'KW' => 'Kuwait',
            'BH' => 'Baréin',
            'OM' => 'Omán',
            'YE' => 'Yemen',
            'AF' => 'Afganistán',
            'PK' => 'Pakistán',
            'BD' => 'Bangladesh',
            'LK' => 'Sri Lanka',
            'NP' => 'Nepal',
            'BT' => 'Bután',
            'MV' => 'Maldivas',
            'KZ' => 'Kazajistán',
            'UZ' => 'Uzbekistán',
            'KG' => 'Kirguistán',
            'TJ' => 'Tayikistán',
            'TM' => 'Turkmenistán',
            'MN' => 'Mongolia',
            'KP' => 'Corea del Norte',
            'TW' => 'Taiwán',
            'HK' => 'Hong Kong',
            'MO' => 'Macao',
            'NZ' => 'Nueva Zelanda'
        ];
        
        return isset($countries[$code]) ? $countries[$code] : $code;
    }
    
    /**
     * Extrae países y capítulos únicos para los filtros
     */
    private function extractFilters() {
        foreach ($this->results as $result) {
            if (!empty($result['country']) && !in_array($result['country'], $this->countries)) {
                $this->countries[] = $result['country'];
            }
            
            if (!empty($result['chapter']) && !in_array($result['chapter'], $this->chapters)) {
                $this->chapters[] = $result['chapter'];
            }
        }
        
        sort($this->countries);
        sort($this->chapters);
    }
    
    /**
     * Filtra los resultados por país y/o capítulo
     */
    public function filterResults($country = null, $chapter = null) {
        $filtered = [];
        
        foreach ($this->results as $result) {
            $matchCountry = true;
            $matchChapter = true;
            
            if ($country) {
                // Búsqueda exacta para evitar coincidencias parciales
                $matchCountry = (strcasecmp($result['country'], $country) === 0) ||
                               (strcasecmp($result['country_code'], $country) === 0);
            }
            
            if ($chapter) {
                $matchChapter = stripos($result['chapter'], $chapter) !== false;
            }
            
            if ($matchCountry && $matchChapter) {
                $filtered[] = $result;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Obtiene capítulos por país
     */
    public function getChaptersByCountry($country = null) {
        $chapters = [];
        
        foreach ($this->results as $result) {
            if ($country) {
                $matchCountry = (strcasecmp($result['country'], $country) === 0) ||
                               (strcasecmp($result['country_code'], $country) === 0);
                if (!$matchCountry) continue;
            }
            
            if (!empty($result['chapter']) && !in_array($result['chapter'], $chapters)) {
                $chapters[] = $result['chapter'];
            }
        }
        
        sort($chapters);
        return $chapters;
    }
    
    /**
     * Obtiene la lista de países disponibles
     */
    public function getCountries() {
        return $this->countries;
    }
    
    /**
     * Obtiene la lista de capítulos disponibles  
     */
    public function getChapters() {
        return $this->chapters;
    }
    
    /**
     * Obtiene todos los resultados sin filtrar
     */
    public function getAllResults() {
        return $this->results;
    }
    
    /**
     * Obtiene los datos como JSON para AJAX
     */
    public function getChaptersByCountryJSON($country = null) {
        return json_encode($this->getChaptersByCountry($country));
    }
}

// Manejo de peticiones AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'chapters') {
    header('Content-Type: application/json');
    try {
        $filter = new MultiGPResultsFilter();
        $country = isset($_GET['country']) ? $_GET['country'] : null;
        echo $filter->getChaptersByCountryJSON($country);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// Uso del script
try {
    $filter = new MultiGPResultsFilter();
    
    // Obtener parámetros de filtro de la URL
    $selectedCountry = isset($_GET['country']) ? $_GET['country'] : null;
    $selectedChapter = isset($_GET['chapter']) ? $_GET['chapter'] : null;
    
    // Aplicar filtros
    $results = $filter->filterResults($selectedCountry, $selectedChapter);
    $countries = $filter->getCountries();
    $chapters = $filter->getChapters();
    $chaptersForCountry = $filter->getChaptersByCountry($selectedCountry);
    
} catch (Exception $e) {
    $error = $e->getMessage();
    $results = [];
    $countries = [];
    $chapters = [];
    $chaptersForCountry = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MultiGP Season Results 2025 - Filtros</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #667eea;
        }
        
        .header h1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 2.5em;
            margin: 0;
            font-weight: bold;
        }
        
        .filters {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-radius: 15px;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .filter-row {
            display: flex;
            gap: 20px;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            background: white;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        select:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        select:disabled {
            background: #f8f9fa;
            color: #6c757d;
            cursor: not-allowed;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
        }
        
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .btn-secondary:hover {
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.4);
        }
        
        .results-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }
        
        .results-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
        }
        
        .results-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }
        
        .results-table tr:hover {
            background: #f8f9fa;
        }
        
        .rank {
            font-weight: bold;
            color: #667eea;
            font-size: 16px;
            text-align: center;
        }
        
        .national-rank {
            font-weight: bold;
            color: #28a745;
            font-size: 14px;
            text-align: center;
            background: #d4edda;
            border-radius: 5px;
            padding: 4px 8px;
            display: inline-block;
            min-width: 30px;
        }
        
        .national-rank.first {
            background: #ffd700;
            color: #b8860b;
        }
        
        .national-rank.second {
            background: #c0c0c0;
            color: #696969;
        }
        
        .national-rank.third {
            background: #cd7f32;
            color: #8b4513;
        }
        
        .pilot-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .country-flag {
            width: 24px;
            height: auto;
            border-radius: 3px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        .pilot-name {
            font-weight: 600;
            color: #333;
        }
        
        .pilot-nickname {
            color: #667eea;
            font-style: italic;
            font-size: 0.9em;
        }
        
        .race-time {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #28a745;
            font-size: 16px;
        }
        
        .chapter {
            font-size: 0.9em;
            color: #666;
        }
        
        .stats {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid #28a745;
        }
        
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .no-results h3 {
            color: #333;
            margin-bottom: 15px;
        }
        
        .error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid #dc3545;
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        /* Definir anchos específicos para las columnas */
        .results-table th:nth-child(1), /* Rank Mundial */
        .results-table td:nth-child(1) {
            width: 80px;
            min-width: 80px;
        }
        
        .results-table th:nth-child(2), /* Rank Nacional */
        .results-table td:nth-child(2) {
            width: 100px;
            min-width: 100px;
            text-align: center;
        }
        
        .results-table th:nth-child(3), /* Piloto */
        .results-table td:nth-child(3) {
            width: 250px;
            min-width: 200px;
        }
        
        .results-table th:nth-child(4), /* País */
        .results-table td:nth-child(4) {
            width: 150px;
            min-width: 120px;
        }
        
        .results-table th:nth-child(5), /* Tiempo */
        .results-table td:nth-child(5) {
            width: 100px;
            min-width: 80px;
            text-align: center;
        }
        
        .results-table th:nth-child(6), /* Capítulo */
        .results-table td:nth-child(6) {
            width: 200px;
            min-width: 150px;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .container {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .button-group {
                justify-content: center;
                margin-top: 15px;
            }
            
            .results-container {
                margin: 0 -20px;
                border-radius: 0;
                box-shadow: none;
                border-top: 1px solid #e9ecef;
                border-bottom: 1px solid #e9ecef;
            }
            
            .results-table {
                font-size: 13px;
                min-width: 800px;
            }
            
            .results-table th,
            .results-table td {
                padding: 10px 8px;
            }
            
            .results-table th:nth-child(3),
            .results-table td:nth-child(3) {
                min-width: 180px;
            }
            .results-table th:nth-child(4),
           .results-table td:nth-child(4) {
               min-width: 110px;
           }
           
           .results-table th:nth-child(6),
           .results-table td:nth-child(6) {
               min-width: 130px;
           }
           
           .results-container::after {
               content: "← Desliza para ver más →";
               display: block;
               text-align: center;
               padding: 10px;
               background: #f8f9fa;
               color: #666;
               font-size: 12px;
               font-style: italic;
               border-top: 1px solid #e9ecef;
           }
           
           .results-container:not([scrollable])::after {
               display: none;
           }
           
           .pilot-info {
               display: block;
           }
           
           .pilot-name {
               font-size: 13px;
               line-height: 1.3;
           }
           
           .pilot-nickname {
               font-size: 11px;
               margin-top: 2px;
           }
           
           .country-flag {
               width: 20px;
               height: auto;
           }
           
           .race-time {
               font-size: 14px;
           }
           
           .chapter {
               font-size: 11px;
               line-height: 1.2;
           }
           
           .stats {
               padding: 15px;
               font-size: 14px;
           }
       }
       
       .results-container {
           scrollbar-width: thin;
           scrollbar-color: #667eea #f1f1f1;
       }
       
       .results-container::-webkit-scrollbar {
           height: 8px;
       }
       
       .results-container::-webkit-scrollbar-track {
           background: #f1f1f1;
           border-radius: 4px;
       }
       
       .results-container::-webkit-scrollbar-thumb {
           background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
           border-radius: 4px;
       }
       
       .results-container::-webkit-scrollbar-thumb:hover {
           background: linear-gradient(135deg, #5a67d8 0%, #6b4696 100%);
       }
   </style>
</head>
<body>
   <div class="container">
       <div class="header">
           <h1>🏁 MultiGP Season Results 2025</h1>
           <p>Resultados oficiales con filtros avanzados y ranking nacional</p>
       </div>
       
       <?php if (isset($error)): ?>
           <div class="error">
               <strong>❌ Error:</strong> <?php echo htmlspecialchars($error); ?>
           </div>
       <?php endif; ?>
       
       <div class="filters">
           <form method="GET" action="" id="filterForm">
               <div class="filter-row">
                   <div class="filter-group">
                       <label for="country">🌍 Filtrar por País:</label>
                       <select name="country" id="country">
                           <option value="">Todos los países</option>
                           <?php foreach ($countries as $country): ?>
                               <option value="<?php echo htmlspecialchars($country); ?>" 
                                       <?php echo ($selectedCountry === $country) ? 'selected' : ''; ?>>
                                   <?php echo htmlspecialchars($country); ?>
                               </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
                   
                   <div class="filter-group">
                       <label for="chapter">🏆 Filtrar por Capítulo:</label>
                       <select name="chapter" id="chapter">
                           <option value="">Todos los capítulos</option>
                           <?php 
                           $chaptersToShow = $selectedCountry ? $chaptersForCountry : $chapters;
                           foreach ($chaptersToShow as $chapter): ?>
                               <option value="<?php echo htmlspecialchars($chapter); ?>"
                                       <?php echo ($selectedChapter === $chapter) ? 'selected' : ''; ?>>
                                   <?php echo htmlspecialchars($chapter); ?>
                               </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
                   
                   <div class="button-group">
                       <button type="submit">🔍 Filtrar</button>
                       <button type="button" class="btn-secondary" onclick="window.location.href='?'">🔄 Limpiar</button>
                   </div>
               </div>
           </form>
       </div>
       
       <?php if (!empty($results)): ?>
           <div class="stats">
               <strong>📊 Estadísticas:</strong> 
               Mostrando <?php echo count($results); ?> pilotos
               <?php if ($selectedCountry): ?>
                   | País: <strong><?php echo htmlspecialchars($selectedCountry); ?></strong>
               <?php endif; ?>
               <?php if ($selectedChapter): ?>
                   | Capítulo: <strong><?php echo htmlspecialchars($selectedChapter); ?></strong>
               <?php endif; ?>
           </div>
           
           <div class="results-container">
               <table class="results-table">
                   <thead>
                       <tr>
                           <th>🌍 Rank</th>
                           <th>🏆 País</th>
                           <th>Piloto</th>
                           <th>País</th>
                           <th>Tiempo</th>
                           <th>Capítulo</th>
                       </tr>
                   </thead>
                   <tbody>
                       <?php foreach ($results as $result): ?>
                           <tr>
                               <td class="rank"><?php echo htmlspecialchars($result['rank']); ?></td>
                               <td>
                                   <?php 
                                   $nationalRankClass = '';
                                   if ($result['national_rank'] == 1) $nationalRankClass = ' first';
                                   elseif ($result['national_rank'] == 2) $nationalRankClass = ' second';
                                   elseif ($result['national_rank'] == 3) $nationalRankClass = ' third';
                                   ?>
                                   <span class="national-rank<?php echo $nationalRankClass; ?>">
                                       <?php echo htmlspecialchars($result['national_rank']); ?>
                                   </span>
                               </td>
                               <td>
                                   <div class="pilot-info">
                                       <div>
                                           <div class="pilot-name"><?php echo htmlspecialchars($result['pilot_name']); ?></div>
                                           <?php if (!empty($result['pilot_nickname'])): ?>
                                               <div class="pilot-nickname">'<?php echo htmlspecialchars($result['pilot_nickname']); ?>'</div>
                                           <?php endif; ?>
                                       </div>
                                   </div>
                               </td>
                               <td>
                                   <div style="display: flex; align-items: center; gap: 8px;">
                                       <img src="https://www.multigp.com/mgp/images/flags/<?php echo strtolower($result['country_code']); ?>.png" 
                                            class="country-flag" alt="<?php echo htmlspecialchars($result['country']); ?>">
                                       <?php echo htmlspecialchars($result['country']); ?>
                                   </div>
                               </td>
                               <td>
                                   <?php if (!empty($result['race_time_formatted'])): ?>
                                       <span class="race-time"><?php echo htmlspecialchars($result['race_time_formatted']); ?></span>
                                   <?php elseif (!empty($result['race_time'])): ?>
                                       <span class="race-time"><?php echo htmlspecialchars($result['race_time']); ?>s</span>
                                   <?php endif; ?>
                               </td>
                               <td class="chapter"><?php echo htmlspecialchars($result['chapter']); ?></td>
                           </tr>
                       <?php endforeach; ?>
                   </tbody>
               </table>
           </div>
       <?php else: ?>
           <div class="no-results">
               <h3>📭 No se encontraron resultados</h3>
               <p>Intenta ajustar los filtros o verifica la conexión con MultiGP.</p>
           </div>
       <?php endif; ?>
   </div>
   
   <script>
       // Función para cargar capítulos por país
       function loadChaptersByCountry(country) {
           const chapterSelect = document.getElementById('chapter');
           const filterForm = document.getElementById('filterForm');
           
           // Añadir clase de loading
           chapterSelect.disabled = true;
           chapterSelect.classList.add('loading');
           
           // Limpiar opciones actuales excepto la primera
           chapterSelect.innerHTML = '<option value="">Todos los capítulos</option>';
           
           if (!country) {
               // Si no hay país seleccionado, cargar todos los capítulos
               fetch('?ajax=chapters')
                   .then(response => response.json())
                   .then(chapters => {
                       chapters.forEach(chapter => {
                           const option = document.createElement('option');
                           option.value = chapter;
                           option.textContent = chapter;
                           chapterSelect.appendChild(option);
                       });
                       chapterSelect.disabled = false;
                       chapterSelect.classList.remove('loading');
                   })
                   .catch(error => {
                       console.error('Error:', error);
                       chapterSelect.disabled = false;
                       chapterSelect.classList.remove('loading');
                   });
           } else {
               // Cargar capítulos específicos del país
               fetch(`?ajax=chapters&country=${encodeURIComponent(country)}`)
                   .then(response => response.json())
                   .then(chapters => {
                       chapters.forEach(chapter => {
                           const option = document.createElement('option');
                           option.value = chapter;
                           option.textContent = chapter;
                           chapterSelect.appendChild(option);
                       });
                       chapterSelect.disabled = false;
                       chapterSelect.classList.remove('loading');
                   })
                   .catch(error => {
                       console.error('Error:', error);
                       chapterSelect.disabled = false;
                       chapterSelect.classList.remove('loading');
                   });
           }
       }
       
       // Función para detectar si la tabla necesita scroll horizontal
       function checkTableScroll() {
           const container = document.querySelector('.results-container');
           if (container) {
               const hasScroll = container.scrollWidth > container.clientWidth;
               if (hasScroll) {
                   container.setAttribute('scrollable', 'true');
               } else {
                   container.removeAttribute('scrollable');
               }
           }
       }
       
       // Event listeners
       document.addEventListener('DOMContentLoaded', function() {
           checkTableScroll();
           
           // Listener para cambio de país
           const countrySelect = document.getElementById('country');
           countrySelect.addEventListener('change', function() {
               const selectedCountry = this.value;
               
               // Limpiar selección de capítulo
               document.getElementById('chapter').value = '';
               
               // Cargar capítulos del país seleccionado
               loadChaptersByCountry(selectedCountry);
           });
           
           // Mejorar la experiencia de scroll en móvil
           const container = document.querySelector('.results-container');
           if (container) {
               let isScrolling = false;
               
               container.addEventListener('scroll', function() {
                   if (!isScrolling) {
                       container.classList.add('scrolling');
                       
                       clearTimeout(isScrolling);
                       isScrolling = setTimeout(function() {
                           container.classList.remove('scrolling');
                           isScrolling = false;
                       }, 150);
                   }
               });
           }
       });
       
       // Ejecutar al cambiar el tamaño de la ventana
       window.addEventListener('resize', checkTableScroll);
       
       // Auto-submit en cambio de filtros (opcional)
        document.getElementById('country').addEventListener('change', function() {
            this.form.submit(); // Siempre hace submit, incluso para "Todos los países"
        });
        
        document.getElementById('chapter').addEventListener('change', function() {
            this.form.submit(); // Siempre hace submit, incluso para "Todos los capítulos"
        });
   </script>
</body>
</html>