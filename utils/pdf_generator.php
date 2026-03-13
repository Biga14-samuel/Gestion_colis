<?php
/**
 * PDF Generator Utility - Gestion_Colis
 * Génère des PDFs pour les colis, factures, reçus, etc.
 * Classe HTML-to-PDF simplifiée sans dépendance FPDF
 */

// Définir les constantes de base de données si non définies
if (!defined('DB_DSN')) {
    define('DB_DSN', 'mysql:host=localhost;dbname=gestion_colis;charset=utf8mb4');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}

/**
 * Classe PDF simplifiée qui génère du HTML imprimable
 */
class SimplePDF {
    private $pages = [];
    private $currentPage = null;
    private $title = '';
    private $orientation = 'P';
    
    public function __construct($orientation = 'P') {
        $this->orientation = $orientation;
        $this->AddPage();
    }
    
    public function AddPage() {
        $this->currentPage = [
            'width' => $this->orientation === 'P' ? 210 : 297,
            'height' => $this->orientation === 'P' ? 297 : 210,
            'content' => ''
        ];
        $this->pages[] = $this->currentPage;
        return $this;
    }
    
    public function SetTitle($title) {
        $this->title = $title;
        return $this;
    }
    
    public function SetFont($family, $style = '', $size = 12) {
        // Simulation - pas de changement visuel en HTML
        return $this;
    }
    
    public function SetTextColor($r, $g = null, $b = null) {
        if ($g === null) $g = $r;
        if ($b === null) $b = $r;
        $this->currentPage['content'] .= '<span style="color: rgb('.$r.','.$g.','.$b.');">';
        return $this;
    }
    
    public function SetFillColor($r, $g = null, $b = null) {
        return $this;
    }
    
    public function SetDrawColor($r, $g = null, $b = null) {
        return $this;
    }
    
    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = 'L', $fill = false) {
        $borderStr = $border ? 'border: 1px solid #000;' : '';
        $bgStr = $fill ? 'background-color: #f0f0f0;' : '';
        $alignStr = 'text-align: ' . ($align === 'R' ? 'right' : ($align === 'C' ? 'center' : 'left')) . ';';
        
        $this->currentPage['content'] .= '<div style="display: inline-block; width: '.$w.'mm; height: '.($h ?: 5).'mm; '.$borderStr.$bgStr.$alignStr.'vertical-align: top; padding: 1px;">'.htmlspecialchars($txt).'</div>';
        
        if ($ln) {
            $this->Ln($h);
        }
        return $this;
    }
    
    public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false) {
        $borderStr = $border ? 'border: 1px solid #000;' : '';
        $bgStr = $fill ? 'background-color: #f0f0f0;' : '';
        
        $this->currentPage['content'] .= '<div style="display: block; width: '.$w.'mm; min-height: '.$h.'mm; '.$borderStr.$bgStr.'word-wrap: break-word;">'.nl2br(htmlspecialchars($txt)).'</div>';
        return $this;
    }
    
    public function Ln($h = null) {
        $this->currentPage['content'] .= '<br style="clear: both;">';
        return $this;
    }
    
    public function Line($x1, $y1, $x2, $y2) {
        $length = sqrt(pow($x2 - $x1, 2) + pow($y2 - $y1, 2));
        $angle = atan2($y2 - $y1, $x2 - $x1) * 180 / M_PI;
        
        $this->currentPage['content'] .= '<div style="position: absolute; left: '.$x1.'mm; top: '.$y1.'mm; width: '.$length.'mm; height: 1px; background: #000; transform: rotate('.$angle.'deg); transform-origin: 0 0;"></div>';
        return $this;
    }
    
    public function Rect($x, $y, $w, $h, $style = '') {
        $fill = strpos($style, 'F') !== false;
        $border = strpos($style, 'D') !== false || $fill || $style === '';
        
        $this->currentPage['content'] .= '<div style="position: absolute; left: '.$x.'mm; top: '.$y.'mm; width: '.$w.'mm; height: '.$h.'mm;'.($border ? 'border: 1px solid #000;' : '').($fill ? 'background-color: #f0f0f0;' : '').'"></div>';
        return $this;
    }
    
    public function Output($dest = 'I', $name = 'document.pdf') {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>'.htmlspecialchars($this->title).'</title>
    <style>
        @page { size: '.($this->orientation === 'P' ? 'A4' : 'A4 landscape').'; margin: 0; }
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; }
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 15mm;
            margin: 10px auto;
            box-sizing: border-box;
            position: relative;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            page-break-after: always;
        }
        @media print {
            .page { margin: 0; box-shadow: none; }
        }
        .page:last-child { page-break-after: avoid; }
    </style>
</head>
<body>';
        
        foreach ($this->pages as $page) {
            $html .= '<div class="page">' . $page['content'] . '</div>';
        }
        
        $html .= '</body></html>';
        
        if ($dest === 'I' || $dest === 'D') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: '.($dest === 'D' ? 'attachment' : 'inline').'; filename="'.$name.'"');
            echo $html;
        } elseif ($dest === 'S') {
            return $html;
        }
    }
}

/**
 * Alias pour la compatibilité avec l'ancien code
 */
class PDF extends SimplePDF {
    public function __construct($orientation = 'P') {
        parent::__construct($orientation);
    }
}
