<?php

/**
 * Script to fix @Request annotations for Symfony 6.4 compatibility
 * This replaces @Request annotations with direct request parameter fetching
 */

$directories = [
    __DIR__ . '/app/system/modules',
    __DIR__ . '/app/installer/src',
    __DIR__ . '/packages'
];

$fixedFiles = [];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getFilename(), 'Controller') !== false) {
            $filePath = $file->getPathname();
            $content = file_get_contents($filePath);
            $originalContent = $content;
            
            // Find all methods with @Request annotations
            $pattern = '/(\s*\/\*\*[^}]*?@Request\([^)]+\)[^}]*?\*\/\s*public\s+function\s+(\w+)\s*\([^)]*\))/s';
            
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $fullMatch = $match[0];
                    $methodName = $match[2];
                    
                    // Skip if already fixed (no parameters in method signature after @Request)
                    if (strpos($fullMatch, '@Request') !== false && preg_match('/public\s+function\s+\w+\s*\(\s*\)/', $fullMatch)) {
                        continue; // Already fixed
                    }
                    
                    // Parse @Request parameters
                    if (preg_match('/@Request\(([^)]+)\)/', $fullMatch, $requestMatch)) {
                        $requestParams = $requestMatch[1];
                        
                        // Remove @Request line
                        $newDocblock = preg_replace('/\s*\*\s*@Request\([^)]+\)/', '', $fullMatch);
                        
                        // Change method signature to have no parameters (or only route params)
                        $newDocblock = preg_replace(
                            '/public\s+function\s+' . preg_quote($methodName) . '\s*\([^)]*\)/',
                            'public function ' . $methodName . '()',
                            $newDocblock
                        );
                        
                        $content = str_replace($fullMatch, $newDocblock, $content);
                        
                        // Add parameter fetching code at the beginning of the method
                        $methodBody = getMethodBody($content, $methodName);
                        if ($methodBody !== false) {
                            $paramCode = generateParamFetchingCode($requestParams, $methodName);
                            $content = insertCodeAfterMethodSignature($content, $methodName, $paramCode);
                        }
                    }
                }
                
                if ($content !== $originalContent) {
                    // Backup original file
                    copy($filePath, $filePath . '.bak');
                    
                    // Write fixed content
                    file_put_contents($filePath, $content);
                    $fixedFiles[] = str_replace(__DIR__ . '/', '', $filePath);
                    echo "Fixed: " . str_replace(__DIR__ . '/', '', $filePath) . "\n";
                }
            }
        }
    }
}

function getMethodBody($content, $methodName) {
    $pattern = '/public\s+function\s+' . preg_quote($methodName) . '\s*\([^)]*\)\s*(?::\s*\w+)?\s*\{/';
    if (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
        return $match[0][1] + strlen($match[0][0]);
    }
    return false;
}

function generateParamFetchingCode($requestParams, $methodName) {
    $code = "\n        // Get parameters from request (Symfony 6.4 compatibility)\n";
    $code .= "        \$request = \\Pagekit\\Application::request();\n";
    
    // Parse the @Request parameters
    // Handle different formats: {"param": "type"}, {"param"}, etc.
    $params = [];
    $csrf = false;
    
    // Check for csrf parameter
    if (strpos($requestParams, 'csrf=true') !== false || strpos($requestParams, 'csrf = true') !== false) {
        $csrf = true;
        $requestParams = preg_replace('/,?\s*csrf\s*=\s*true/', '', $requestParams);
    }
    
    // Parse JSON-like parameter definitions
    if (preg_match('/\{([^}]+)\}/', $requestParams, $match)) {
        $paramStr = $match[1];
        
        // Split by comma, but respect quotes
        preg_match_all('/"([^"]+)"\s*:\s*"?([^",}]+)"?/', $paramStr, $paramMatches, PREG_SET_ORDER);
        
        foreach ($paramMatches as $pm) {
            $paramName = $pm[1];
            $paramType = trim($pm[2], '"');
            $params[$paramName] = $paramType;
        }
    }
    
    // Generate fetching code based on method type (GET/POST)
    $isGet = (stripos($methodName, 'index') !== false || stripos($methodName, 'get') !== false || 
              stripos($methodName, 'count') !== false || stripos($methodName, 'list') !== false);
    
    foreach ($params as $paramName => $paramType) {
        if ($isGet) {
            // GET parameters
            if ($paramType === 'array') {
                $code .= "        \$$paramName = \$request->query->all()['$paramName'] ?? [];\n";
            } elseif ($paramType === 'int' || $paramType === 'integer') {
                $code .= "        \$$paramName = (int) \$request->query->get('$paramName', 0);\n";
            } elseif ($paramType === 'bool' || $paramType === 'boolean') {
                $code .= "        \$$paramName = (bool) \$request->query->get('$paramName', false);\n";
            } else {
                $code .= "        \$$paramName = \$request->query->get('$paramName', '');\n";
            }
        } else {
            // POST parameters - check both form data and JSON body
            if ($paramType === 'array') {
                $code .= "        \$$paramName = \$request->request->all()['$paramName'] ?? [];\n";
                $code .= "        if (empty(\$$paramName) && \$request->getContent()) {\n";
                $code .= "            \$json = json_decode(\$request->getContent(), true);\n";
                $code .= "            \$$paramName = \$json['$paramName'] ?? [];\n";
                $code .= "        }\n";
            } elseif ($paramType === 'int' || $paramType === 'integer') {
                $code .= "        \$$paramName = (int) (\$request->request->get('$paramName') ?? \$request->get('$paramName', 0));\n";
            } elseif ($paramType === 'bool' || $paramType === 'boolean') {
                $code .= "        \$$paramName = (bool) (\$request->request->get('$paramName') ?? \$request->get('$paramName', false));\n";
            } else {
                $code .= "        \$$paramName = \$request->request->get('$paramName') ?? \$request->get('$paramName', '');\n";
            }
        }
    }
    
    $code .= "        \n";
    return $code;
}

function insertCodeAfterMethodSignature($content, $methodName, $code) {
    $pattern = '/(public\s+function\s+' . preg_quote($methodName) . '\s*\([^)]*\)\s*(?::\s*[^{]+)?\s*\{)/';
    
    if (preg_match($pattern, $content, $match)) {
        $replacement = $match[0] . $code;
        $content = preg_replace($pattern, $replacement, $content, 1);
    }
    
    return $content;
}

echo "\n=== Fixed " . count($fixedFiles) . " files ===\n";
foreach ($fixedFiles as $file) {
    echo "  - $file\n";
}