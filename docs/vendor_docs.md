# Vendor - Can Accept Service

## O que é

`can_accept_service` é um accessor calculado no modelo `Vendor` que determina se um vendor pode aceitar novos serviços. Retorna `true` ou `false` baseado em 7 condições.

## Onde está definido

**Arquivo:** `app/Models/Vendor.php` (linhas 71-82)

```php
public function canAcceptService(): Attribute
{
    return Attribute::make(get: function () {
        return $this->user?->hasVerifiedPhoneNumber() &&
            $this->user?->hasVerifiedEmail() &&
            $this->all_documents_verified &&
            ! $this->openServices()->exists() &&
            $this->iban != null &&
            $this->invoice_workspace != null &&
            str_contains($this->at_user, '/');
    })->shouldCache();
}
```

## Condições necessárias

1. **Telefone verificado** - `phone_number_verified_at` não é null
2. **Email verificado** - `email_verified_at` não é null
3. **Todos os documentos aprovados** - `all_documents_verified` (documentos requeridos + certificações das áreas de operação)
4. **Sem serviços abertos** - Não há serviços com status PENDING/ACCEPTED/FINISHED/ARRIVED e payment_status PAID/PENDING
5. **IBAN preenchido** - Campo `iban` não é null
6. **Workspace de faturação criado** - Campo `invoice_workspace` não é null
7. **AT User válido** - Campo `at_user` contém o caractere '/'

## Onde é usado

- **VendorSearchService** - Filtra vendors disponíveis na busca de serviços
- **StatusController** - Valida antes de permitir vendor ficar ONLINE
- **CalculateServicePriceForCustomer** - Valida antes de calcular preço
- **Filament Backoffice** - Exibição na tabela e filtro de busca

## Notas técnicas

- É um **accessor calculado** (não é coluna no banco de dados)
- Adicionado ao array via `$appends = ['can_accept_service']`
- Usa cache (`shouldCache()`) para melhorar performance
- Calculado dinamicamente a cada acesso
