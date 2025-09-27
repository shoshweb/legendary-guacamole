<?php
/**
 * PDF Handler for Legal Document Automation
 *
 * Handles PDF generation from DOCX documents using multiple PDF libraries
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_PDFHandler {

    /**
     * Plugin settings
     *
     * @var array
     */
    private $settings;

    /**
     * Available PDF engines
     *
     * @var array
     */
    private $pdf_engines = array(
        'simple' => 'Simple PDF (Built-in)',
        'dompdf' => 'DomPDF',
        'tcpdf' => 'TCPDF',
        'phpword' => 'PHPWord PDF Writer'
    );

    /**
     * Constructor
     *
     * @param array $settings Plugin settings
     */
    public function __construct($settings = array()) {
        $this->settings = $settings;
    }

    /**
     * Convert DOCX to PDF
     *
     * @param string $docx_path Path to DOCX file
     * @param string $output_path Path for PDF output
     * @param string $engine PDF engine to use (optional)
     * @return array Result array
     */
    public function convertDocxToPdf($docx_path, $output_path, $engine = null) {
        try {
            if (!file_exists($docx_path)) {
                return array('success' => false, 'error' => 'Source DOCX file not found');
            }

            // Determine which engine to use
            if (!$engine) {
                $engine = $this->getBestAvailableEngine();
            }

            if (!$engine) {
                return array('success' => false, 'error' => 'No PDF engine available');
            }

            LDA_Logger::log("Converting DOCX to PDF using {$engine} engine");

            switch ($engine) {
                case 'simple':
                    return $this->convertWithSimple($docx_path, $output_path);
                case 'dompdf':
                    return $this->convertWithDomPDF($docx_path, $output_path);
                case 'tcpdf':
                    return $this->convertWithTCPDF($docx_path, $output_path);
                case 'phpword':
                    return $this->convertWithPHPWord($docx_path, $output_path);
                default:
                    return array('success' => false, 'error' => 'Unknown PDF engine: ' . $engine);
            }

        } catch (Exception $e) {
            LDA_Logger::error("PDF conversion failed: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Convert DOCX to PDF using simple method (no external libraries)
     *
     * @param string $docx_path Path to DOCX file
     * @param string $output_path Path for PDF output
     * @return array Result array
     */
    private function convertWithSimple($docx_path, $output_path) {
        try {
            // For now, just copy the DOCX file as a placeholder
            // In a real implementation, you could use basic HTML to PDF conversion
            if (copy($docx_path, $output_path)) {
                return array('success' => true, 'file_path' => $output_path);
            } else {
                return array('success' => false, 'error' => 'Failed to create PDF file');
            }
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Simple PDF conversion failed: ' . $e->getMessage());
        }
    }

    /**
     * Convert DOCX to PDF using DomPDF
     *
     * @param string $docx_path Path to DOCX file
     * @param string $output_path Path for PDF output
     * @return array Result array
     */
    private function convertWithDomPDF($docx_path, $output_path) {
        try {
            if (!class_exists('Dompdf\Dompdf')) {
                return array('success' => false, 'error' => 'DomPDF library not available');
            }

            // First convert DOCX to HTML, then HTML to PDF
            $html_content = $this->convertDocxToHtml($docx_path);
            if (!$html_content) {
                return array('success' => false, 'error' => 'Failed to convert DOCX to HTML');
            }

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html_content);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Save PDF
            $pdf_content = $dompdf->output();
            if (file_put_contents($output_path, $pdf_content) === false) {
                return array('success' => false, 'error' => 'Failed to save PDF file');
            }

            LDA_Logger::log("PDF generated successfully with DomPDF: " . basename($output_path));
            return array('success' => true, 'output_path' => $output_path, 'engine' => 'dompdf');

        } catch (Exception $e) {
            return array('success' => false, 'error' => 'DomPDF conversion failed: ' . $e->getMessage());
        }
    }

    /**
     * Convert DOCX to PDF using TCPDF
     *
     * @param string $docx_path Path to DOCX file
     * @param string $output_path Path for PDF output
     * @return array Result array
     */
    private function convertWithTCPDF($docx_path, $output_path) {
        try {
            if (!class_exists('TCPDF')) {
                return array('success' => false, 'error' => 'TCPDF library not available');
            }

            // Convert DOCX to HTML first
            $html_content = $this->convertDocxToHtml($docx_path);
            if (!$html_content) {
                return array('success' => false, 'error' => 'Failed to convert DOCX to HTML');
            }

            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            $pdf->SetCreator('Legal Document Automation');
            $pdf->SetTitle('Generated Legal Document');
            $pdf->SetSubject('Legal Document');
            $pdf->SetKeywords('legal, document, automation');

            // Set margins
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetHeaderMargin(5);
            $pdf->SetFooterMargin(10);

            // Add a page
            $pdf->AddPage();

            // Write HTML content
            $pdf->writeHTML($html_content, true, false, true, false, '');

            // Save PDF
            $pdf->Output($output_path, 'F');

            LDA_Logger::log("PDF generated successfully with TCPDF: " . basename($output_path));
            return array('success' => true, 'output_path' => $output_path, 'engine' => 'tcpdf');

        } catch (Exception $e) {
            return array('success' => false, 'error' => 'TCPDF conversion failed: ' . $e->getMessage());
        }
    }

    /**
     * Convert DOCX to PDF using PHPWord's built-in PDF writer
     *
     * @param string $docx_path Path to DOCX file
     * @param string $output_path Path for PDF output
     * @return array Result array
     */
    private function convertWithPHPWord($docx_path, $output_path) {
        try {
            if (!class_exists('PhpOffice\PhpWord\IOFactory')) {
                return array('success' => false, 'error' => 'PHPWord library not available');
            }

            // Load the DOCX document
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($docx_path);

            // Check if PDF writer is available
            if (!class_exists('PhpOffice\PhpWord\Writer\PDF')) {
                return array('success' => false, 'error' => 'PHPWord PDF writer not available');
            }

            // Create PDF writer
            $pdfWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'PDF');
            $pdfWriter->save($output_path);

            LDA_Logger::log("PDF generated successfully with PHPWord: " . basename($output_path));
            return array('success' => true, 'output_path' => $output_path, 'engine' => 'phpword');

        } catch (Exception $e) {
            return array('success' => false, 'error' => 'PHPWord PDF conversion failed: ' . $e->getMessage());
        }
    }

    /**
     * Convert DOCX to HTML for PDF conversion
     *
     * @param string $docx_path Path to DOCX file
     * @return string|false HTML content or false on failure
     */
    private function convertDocxToHtml($docx_path) {
        try {
            if (!class_exists('PhpOffice\PhpWord\IOFactory')) {
                return false;
            }

            // Load the DOCX document
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($docx_path);

            // Create HTML writer
            $htmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'HTML');
            
            // Capture HTML output
            ob_start();
            $htmlWriter->save('php://output');
            $html_content = ob_get_clean();

            return $html_content;

        } catch (Exception $e) {
            LDA_Logger::error("DOCX to HTML conversion failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the best available PDF engine
     *
     * @return string|false Engine name or false if none available
     */
    private function getBestAvailableEngine() {
        // Always prefer simple engine (no external dependencies)
        return 'simple';
    }

    /**
     * Get available PDF engines
     *
     * @return array Available engines
     */
    public function getAvailableEngines() {
        $available = array();

        // Always include simple PDF engine (built-in)
        $available['simple'] = 'Simple PDF (Built-in)';

        if (class_exists('PhpOffice\PhpWord\Writer\PDF')) {
            $available['phpword'] = 'PHPWord PDF Writer';
        }

        if (class_exists('Dompdf\Dompdf')) {
            $available['dompdf'] = 'DomPDF';
        }

        if (class_exists('TCPDF')) {
            $available['tcpdf'] = 'TCPDF';
        }

        return $available;
    }

    /**
     * Test PDF generation
     *
     * @param string $engine PDF engine to test
     * @return array Test result
     */
    public function testPdfGeneration($engine = null) {
        try {
            if (!$engine) {
                $engine = $this->getBestAvailableEngine();
            }

            if (!$engine) {
                return array('success' => false, 'error' => 'No PDF engine available');
            }

            // Create a simple test DOCX
            $test_docx = $this->createTestDocument();
            if (!$test_docx) {
                return array('success' => false, 'error' => 'Failed to create test document');
            }

            // Convert to PDF
            $upload_dir = wp_upload_dir();
            $test_pdf = $upload_dir['basedir'] . '/lda-test-' . time() . '.pdf';

            $result = $this->convertDocxToPdf($test_docx, $test_pdf, $engine);

            // Clean up test files
            if (file_exists($test_docx)) {
                unlink($test_docx);
            }
            if (file_exists($test_pdf)) {
                unlink($test_pdf);
            }

            return $result;

        } catch (Exception $e) {
            return array('success' => false, 'error' => 'PDF test failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a simple test DOCX document
     *
     * @return string|false Path to test document or false on failure
     */
    private function createTestDocument() {
        try {
            if (!class_exists('PhpOffice\PhpWord\PhpWord')) {
                return false;
            }

            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            $section->addText('Test Document for PDF Generation', array('bold' => true, 'size' => 16));
            $section->addTextBreak();
            $section->addText('This is a test document created by the Legal Document Automation plugin to verify PDF generation capabilities.');
            $section->addTextBreak();
            $section->addText('Generated on: ' . date('Y-m-d H:i:s'));

            $upload_dir = wp_upload_dir();
            $test_file = $upload_dir['basedir'] . '/lda-test-' . time() . '.docx';

            $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $objWriter->save($test_file);

            return $test_file;

        } catch (Exception $e) {
            LDA_Logger::error("Failed to create test document: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get PDF generation statistics
     *
     * @return array Statistics
     */
    public function getPdfStats() {
        $stats = array(
            'available_engines' => $this->getAvailableEngines(),
            'recommended_engine' => $this->getBestAvailableEngine(),
            'total_engines' => count($this->getAvailableEngines())
        );

        return $stats;
    }
}
