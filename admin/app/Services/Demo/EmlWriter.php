<?php

namespace App\Services\Demo;

/**
 * Genera los `.eml` de la demo.
 *
 * Los adjuntos PDF se construyen a mano (PDF 1.4 mínimo, sin dependencias) con
 * la capa de texto embebida. El caso del "PDF escaneado" usa un PDF sin texto:
 * es el que obliga a Stack A a entrar por OCR, y esa ruta no se puede
 * demostrar con un archivo real de una página.
 */
class EmlWriter
{
    /** @return array<string, array<string, mixed>> */
    public function samples(): array
    {
        return [
            // Expediente completo: debe terminar en `validated`.
            '01-expediente-completo' => [
                'from'    => ['Mariana Ríos', 'mariana.rios@correo.example.com'],
                'to'      => 'tramites@seguros.example.com',
                'subject' => 'Trámite: Póliza nueva — solicitud y documentación',
                'body'    => "Estimado equipo:\n\nAdjunto la documentación para iniciar el trámite de póliza nueva.\n\nSaludos cordiales,\nMariana Ríos",
                'attachments' => [
                    ['solicitud.pdf', $this->pdf('FORMULARIO DE SOLICITUD DE SEGURO', [
                        'Solicitante: Mariana Ríos',
                        'Documento: 1712345678',
                        'Producto: Póliza de vida',
                        'Fecha de solicitud: 05/10/2026',
                    ])],
                    ['cedula.pdf', $this->pdf('CÉDULA DE IDENTIDAD', [
                        'Cédula de identidad',
                        'Nombres: Mariana Ríos Zambrano',
                        'Fecha de nacimiento: 14/03/1991',
                    ])],
                    ['recibo.pdf', $this->pdf('RECIBO POR SERVICIO DE AGUA', [
                        'Comprobante de domicilio',
                        'Cliente: Mariana Ríos Zambrano',
                        'Dirección: Av. Amazonas N34-120, Quito',
                        'Fecha de emisión: 28/09/2026',
                        'Valor total: USD 45,80',
                    ])],
                ],
            ],

            // Falta un documento del corredor → CU-02, respuesta con la lista.
            '02-falta-documento' => [
                'from'    => ['Jorge Araujo', 'jorge.araujo@correo.example.com'],
                'to'      => 'tramites@seguros.example.com',
                'subject' => 'Trámite: Póliza nueva — envío incompleto',
                'body'    => "Buen día,\n\nEnvío lo que tengo disponible por ahora.\n\nGracias.",
                'attachments' => [
                    ['solicitud.pdf', $this->pdf('FORMULARIO DE SOLICITUD DE SEGURO', [
                        'Solicitante: Jorge Araujo',
                        'Producto: Póliza de salud',
                        'Fecha de solicitud: 06/10/2026',
                    ])],
                ],
            ],

            // CU-04: un ejecutable renombrado a PDF. Debe frenar en detect_kind.
            '03-ejecutable-renombrado' => [
                'from'    => ['Karen Ruiz', 'karen.ruiz@correo.example.com'],
                'to'      => 'tramites@seguros.example.com',
                'subject' => 'Trámite: Factura adjunta',
                'body'    => "Adjunto la factura.",
                'attachments' => [
                    ['factura.pdf', $this->fakeExecutable()],
                ],
            ],

            // Sin capa de texto: en Stack A obliga a pdftoppm + OCR.
            '04-pdf-escaneado' => [
                'from'    => ['Diego Paredes', 'diego.paredes@correo.example.com'],
                'to'      => 'tramites@seguros.example.com',
                'subject' => 'Trámite: Factura escaneada',
                'body'    => "La factura viene escaneada, espero sirva.",
                'attachments' => [
                    ['factura_escaneada.pdf', $this->blankPdf()],
                ],
            ],
        ];
    }

    /**
     * @param  array{from: array{0: string, 1: string}, to: string, subject: string, body: string, attachments: array<int, array{0: string, 1: string}>}  $sample
     */
    public function build(array $sample): string
    {
        $boundary = '=_demo_'.bin2hex(random_bytes(8));
        $date = now()->subDays(random_int(0, 2))->toRfc2822String();
        $messageId = '<'.bin2hex(random_bytes(12)).'@demo.local>';

        $headers = [
            'Date: '.$date,
            'From: '.$this->encodeName($sample['from'][0]).' <'.$sample['from'][1].'>',
            'To: '.$sample['to'],
            'Subject: '.$this->encodeName($sample['subject']),
            'Message-ID: '.$messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
        ];

        $body = "--".$boundary."\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($sample['body'])."\r\n";

        foreach ($sample['attachments'] as [$filename, $bytes]) {
            $body .= "--".$boundary."\r\n";
            $body .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $body .= chunk_split(base64_encode($bytes), 76, "\r\n");
        }

        $body .= "--".$boundary."--\r\n";

        return implode("\r\n", $headers)."\r\n\r\n".$body;
    }

    /** Nombres con acentos van codificados o el parser no los lee. */
    private function encodeName(string $value): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $value) === 1
            ? $value
            : '=?UTF-8?B?'.base64_encode($value).'?=';
    }

    /**
     * PDF 1.4 mínimo con una capa de texto.
     *
     * @param  array<int, string>  $lines
     */
    private function pdf(string $title, array $lines): string
    {
        $content = "BT /F1 14 Tf 60 760 Td (".$this->escape($title).") Tj ET\n";
        $y = 730;

        foreach ($lines as $line) {
            $content .= "BT /F1 11 Tf 60 {$y} Td (".$this->escape($line).") Tj ET\n";
            $y -= 18;
        }

        return $this->assemble($content);
    }

    /** PDF sin texto: la ruta de OCR de Stack A. */
    private function blankPdf(): string
    {
        // Un rectángulo gris es indistinguible de una página escaneada vacía
        // para `pdfplumber`, que devolverá 0 caracteres por página.
        $content = "0.85 g 60 600 480 150 re f\n";

        return $this->assemble($content);
    }

    /**
     * Un `.exe` con la cabecera MZ, guardado con nombre de PDF.
     *
     * Es el caso CU-04 literal: los magic bytes empiezan por `MZ`, así que el
     * archivo NO es un PDF aunque su extensión diga `.pdf`. No se le antepone
     * un encabezado PDF falso: eso haría que la detección por magic bytes lo
     * aceptara y el caso dejaría de probar nada.
     */
    private function fakeExecutable(): string
    {
        $exe = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00";
        $exe .= str_repeat("\x00\x00\x00\x00", 64);
        $exe .= "This program cannot be run in DOS mode.\r\n";
        $exe .= str_repeat("\x00", 4096);

        return $exe;
    }

    /** Ensambla el PDF con la tabla xref y el trailer correctos. */
    private function assemble(string $content): string
    {
        $objects = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
                ." /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
            "<< /Length ".strlen($content)." >>\nstream\n".$content."endstream",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);

        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xrefOffset."\n%%EOF\n";

        return $pdf;
    }

    /** Los paréntesis y barras invertidas rompen la cadena del PDF. */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', ' ', ' '],
            $value,
        );
    }
}