<?php
/**
 * Compilador de arquivos PO para MO em PHP puro
 * 
 * Este script converte arquivos .po em .mo quando msgfmt não está disponível
 */

class POtoMOCompiler {
    
    public function compile($po_file, $mo_file = null) {
        if (!file_exists($po_file)) {
            throw new Exception("Arquivo PO não encontrado: $po_file");
        }
        
        if ($mo_file === null) {
            $mo_file = preg_replace('/\.po$/', '.mo', $po_file);
        }
        
        $po_content = file_get_contents($po_file);
        $translations = $this->parsePO($po_content);
        $mo_content = $this->generateMO($translations);
        
        if (file_put_contents($mo_file, $mo_content) === false) {
            throw new Exception("Não foi possível escrever arquivo MO: $mo_file");
        }
        
        return $mo_file;
    }
    
    private function parsePO($content) {
        $translations = [];
        $lines = explode("\n", $content);
        $msgid = '';
        $msgstr = '';
        $in_msgid = false;
        $in_msgstr = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            // msgid start
            if (preg_match('/^msgid\s+"(.*)"$/', $line, $matches)) {
                // Save previous translation if exists
                if (!empty($msgid) && $msgid !== '') {
                    $translations[$msgid] = $msgstr;
                }
                
                $msgid = $matches[1];
                $msgstr = '';
                $in_msgid = true;
                $in_msgstr = false;
                continue;
            }
            
            // msgstr start
            if (preg_match('/^msgstr\s+"(.*)"$/', $line, $matches)) {
                $msgstr = $matches[1];
                $in_msgid = false;
                $in_msgstr = true;
                continue;
            }
            
            // Continuation line
            if (preg_match('/^"(.*)"$/', $line, $matches)) {
                if ($in_msgid) {
                    $msgid .= $matches[1];
                } elseif ($in_msgstr) {
                    $msgstr .= $matches[1];
                }
                continue;
            }
        }
        
        // Save last translation
        if (!empty($msgid) && $msgid !== '') {
            $translations[$msgid] = $msgstr;
        }
        
        return $translations;
    }
    
    private function generateMO($translations) {
        $keys = array_keys($translations);
        $values = array_values($translations);
        
        // Remove empty entries
        $filtered_translations = [];
        foreach ($translations as $key => $value) {
            if (!empty($key)) {
                $filtered_translations[$key] = $value;
            }
        }
        
        $keys = array_keys($filtered_translations);
        $values = array_values($filtered_translations);
        $count = count($keys);
        
        // Calculate offsets
        $key_offsets = [];
        $value_offsets = [];
        $key_lengths = [];
        $value_lengths = [];
        
        $offset = 28 + ($count * 16); // Header + index table
        
        for ($i = 0; $i < $count; $i++) {
            $key_lengths[] = strlen($keys[$i]);
            $key_offsets[] = $offset;
            $offset += strlen($keys[$i]) + 1; // +1 for null terminator
        }
        
        for ($i = 0; $i < $count; $i++) {
            $value_lengths[] = strlen($values[$i]);
            $value_offsets[] = $offset;
            $offset += strlen($values[$i]) + 1; // +1 for null terminator
        }
        
        // Build MO file
        $mo = '';
        
        // Magic number (little endian)
        $mo .= pack('V', 0x950412de);
        
        // Version
        $mo .= pack('V', 0);
        
        // Number of strings
        $mo .= pack('V', $count);
        
        // Offset to key table
        $mo .= pack('V', 28);
        
        // Offset to value table
        $mo .= pack('V', 28 + ($count * 8));
        
        // Hash table size (0 = no hash table)
        $mo .= pack('V', 0);
        
        // Offset to hash table
        $mo .= pack('V', 0);
        
        // Key table
        for ($i = 0; $i < $count; $i++) {
            $mo .= pack('V', $key_lengths[$i]);
            $mo .= pack('V', $key_offsets[$i]);
        }
        
        // Value table
        for ($i = 0; $i < $count; $i++) {
            $mo .= pack('V', $value_lengths[$i]);
            $mo .= pack('V', $value_offsets[$i]);
        }
        
        // Keys
        for ($i = 0; $i < $count; $i++) {
            $mo .= $keys[$i] . "\0";
        }
        
        // Values
        for ($i = 0; $i < $count; $i++) {
            $mo .= $values[$i] . "\0";
        }
        
        return $mo;
    }
}

// Script principal
if (php_sapi_name() === 'cli' || !empty($_SERVER['argv'])) {
    echo "🔧 Compilador PO para MO\n";
    echo "========================\n\n";
    
    $plugin_dir = dirname(__FILE__) . '/../';
    $languages_dir = $plugin_dir . 'languages/';
    
    $po_files = glob($languages_dir . '*.po');
    
    if (empty($po_files)) {
        echo "❌ Nenhum arquivo PO encontrado em: $languages_dir\n";
        exit(1);
    }
    
    $compiler = new POtoMOCompiler();
    $success_count = 0;
    $total_count = count($po_files);
    
    foreach ($po_files as $po_file) {
        $mo_file = preg_replace('/\.po$/', '.mo', $po_file);
        $filename = basename($po_file);
        
        try {
            echo "📝 Compilando: $filename ... ";
            $result = $compiler->compile($po_file, $mo_file);
            
            $mo_size = file_exists($mo_file) ? filesize($mo_file) : 0;
            echo "✅ SUCESSO ($mo_size bytes)\n";
            $success_count++;
            
        } catch (Exception $e) {
            echo "❌ ERRO: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n📊 Relatório:\n";
    echo "   Total de arquivos: $total_count\n";
    echo "   Compilados com sucesso: $success_count\n";
    echo "   Falhas: " . ($total_count - $success_count) . "\n";
    
    if ($success_count === $total_count) {
        echo "\n✅ Todos os arquivos compilados com sucesso!\n";
    } else {
        echo "\n⚠️  Alguns arquivos falharam na compilação.\n";
    }
}
?>