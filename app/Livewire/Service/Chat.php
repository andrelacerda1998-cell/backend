<?php

namespace App\Livewire\Service;

use App\Exceptions\Api\Common\WrongEncryptionKey;
use App\Models\Service;
use Closure;
use Illuminate\Support\Collection;
use Livewire\Component;

class Chat extends Component
{
    public string $sessionKey;
    public string $winWidth;
    public bool $panelHidden;
    public bool $showPositionBtn;

    public Service $service;

    public string $buttonIcon = 'heroicon-m-chat-bubble-bottom-center';

    public ?string $privateKey = null;

    public Collection $messages;

    public function __construct()
    {
        $this->sessionKey = auth()->id() . '-chat-messages';
    }
    public function mount(Service $service)
    {
        $this->panelHidden = session($this->sessionKey . '-panelHidden', true);
        $this->winWidth = "width: 30%;";
        $this->showPositionBtn = true;
        $this->privateKey = $service->rsa;
        $this->service = $service;

        $this->messages = $service->messages;





        //dd($service);
    }

    public function decryptData(string $encodedData): string
    {
        $decodedData = base64_decode($encodedData);

        $success = openssl_private_decrypt($decodedData, $decrypted, $this->privateKey);

        if (!$success) {
            throw new WrongEncryptionKey();
        }

        return mb_convert_encoding($decrypted, 'UTF-8', 'UTF-8');
    }
    public function render()
    {
        return view('livewire.service.chat');
    }

    public function togglePanel(): void
    {
        $this->panelHidden = !$this->panelHidden;
        session([$this->sessionKey . '-panelHidden' => $this->panelHidden]);
    }
}
