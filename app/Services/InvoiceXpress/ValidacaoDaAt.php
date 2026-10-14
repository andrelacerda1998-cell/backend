<?php

namespace App\Services\InvoiceXpress;

use App\Exceptions\Api\Vendor\VendorDuplicatedSequence;
use App\Models\Vendor;

/**
 * Validar o acesso à AT de um técnico: testar as credenciais no InvoiceXpress e
 * guardar o resultado no perfil.
 *
 * É o botão "Validar AT" do Filament (ValidateAtCredentialsAction) e do
 * backoffice (PUT /v1/admin/vendors/{vendor}/at-validation). Os passos viviam
 * dentro da ação do Filament; saíram para aqui para os dois botões fazerem
 * exatamente o mesmo.
 *
 * Pela ordem: os dados fiscais com as credenciais (é aqui que o InvoiceXpress
 * as recusa se estiverem erradas), a comunicação automática com a AT e a série
 * de faturação do ano. Se a série já existir, as credenciais servem.
 *
 * Não emite fatura nenhuma.
 */
final class ValidacaoDaAt
{
    /**
     * Porque é que ainda não se pode validar, em português de backoffice. Null
     * quando se pode.
     */
    public static function bloqueio(Vendor $vendor): ?string
    {
        if (! $vendor->invoice_workspace || ! $vendor->auth_token) {
            return 'Ainda não há conta de faturação: cria primeiro o workspace no InvoiceXpress.';
        }

        if (! $vendor->at_user) {
            return 'O técnico ainda não deu o subutilizador da AT.';
        }

        // Sem a barra o `at_ready` nunca fica verdadeiro: validar marcava a AT
        // como válida e o técnico continuava travado, sem ninguém perceber porquê.
        if (! str_contains($vendor->at_user, '/')) {
            return 'O subutilizador da AT tem de ter a forma NIF/número (por exemplo, 123456789/1). Pede-o de novo ao técnico.';
        }

        return null;
    }

    /**
     * Testa as credenciais e grava o resultado.
     *
     * Se o InvoiceXpress recusar, a AT fica por validar (`at_valid` a falso) e o
     * erro sobe a quem chamou. A data da última validação fica: é a da última
     * vez que funcionou.
     *
     * @throws \Exception
     */
    public static function validar(Vendor $vendor): void
    {
        try {
            // Pelo contentor, como no CreateInvoiceJob: os testes trocam-no por um duplo.
            $invoiceXpress = app(InvoiceVendorService::class, ['vendor' => $vendor]);

            $invoiceXpress->updateFiscalDetails();
            $invoiceXpress->createAtCommunications();

            try {
                $invoiceXpress->createSequence();
            } catch (VendorDuplicatedSequence) {
                // A série já existe: as credenciais servem.
            }
        } catch (\Exception $e) {
            $vendor->at_valid = false;
            $vendor->save();

            throw $e;
        }

        $vendor->at_valid = true;
        $vendor->at_validated_at = now();
        $vendor->save();
    }
}
