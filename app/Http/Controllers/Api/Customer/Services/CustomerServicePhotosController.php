<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Exception;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Fotos que o CLIENTE junta ao pedido, para o técnico perceber o trabalho antes
 * de chegar (a torneira que pinga, o quadro elétrico, o móvel por montar).
 *
 * Não confundir com Vendor\Services\ServicePhotosController: essas são o
 * antes/depois que o técnico tira no local e servem para o proteger numa
 * reclamação. Estas são do lado de cá e servem para ele saber o que o espera.
 *
 * POR QUE É QUE O UPLOAD É SEPARADO DO PAGAMENTO:
 * no checkout o serviço ainda NÃO existe — só nasce quando o pagamento é
 * aceite. As fotos ficam por isso em espera no próprio utilizador
 * (`pending_service_photos`) e são movidas para o serviço no momento em que ele
 * é criado (OpenServiceController::attachCustomerPhotos).
 * Podia-se enviar tudo junto no pedido de pagamento, mas isso punha vários MB
 * de binário dentro da chamada que cobra dinheiro: cada retransmissão passaria a
 * arrastar as imagens, e uma rede fraca ao subir uma foto passaria a ser uma
 * rede fraca a pagar. Separado, as fotos sobem enquanto o cliente ainda está a
 * preencher o resto e o pedido de pagamento continua a ser JSON pequeno.
 *
 * O que fica por decidir e não foi assumido aqui: quem limpa as fotos em espera
 * de quem desiste a meio. Ficam presas ao utilizador, invisíveis, e são poucos
 * KB por caso — merece um comando agendado, mas inventá-lo agora sem saber a
 * política de retenção seria decidir sozinho o que é dado do cliente.
 */
class CustomerServicePhotosController extends Controller
{
    /** Coleção de espera, no utilizador, antes de existir serviço. */
    public const PENDING_COLLECTION = 'pending_service_photos';

    /** Quantas fotos um pedido aceita. Chega para mostrar o problema sem virar álbum. */
    public const MAX_PHOTOS = 5;

    public function store(Request $request)
    {
        $user = auth('api')->user();

        $request->validate([
            // heic/heif porque é o formato por omissão do iPhone: recusá-lo seria
            // recusar a foto que a maioria dos clientes tira.
            'photo' => 'required|file|mimes:jpg,jpeg,png,heic,heif,webp|max:10240',
        ]);

        $pending = $user->getMedia(self::PENDING_COLLECTION);
        if ($pending->count() >= self::MAX_PHOTOS) {
            return new ApiErrorResponse(
                new Exception,
                __('Só podes juntar :max fotografias.', ['max' => self::MAX_PHOTOS]),
                422
            );
        }

        $media = $user->addMediaFromRequest('photo')->toMediaCollection(self::PENDING_COLLECTION);

        return new ApiSuccessResponse([
            'id' => $media->id,
            'url' => $media->getTemporaryUrl(now()->addMinutes(30)),
        ]);
    }

    /**
     * Apagar uma foto ainda em espera (o cliente enganou-se antes de pagar).
     *
     * Só apaga media que seja DESTE utilizador e que esteja na coleção de
     * espera: sem as duas condições, um id adivinhado apagava ficheiros de
     * outra pessoa — ou o antes/depois de um serviço já executado.
     */
    public function destroy(Media $media)
    {
        $user = auth('api')->user();

        $isOwn = $media->model_type === $user->getMorphClass() && (int) $media->model_id === (int) $user->id;

        if (! $isOwn || $media->collection_name !== self::PENDING_COLLECTION) {
            return new ApiErrorResponse(new Exception, 'Photo not found', 404);
        }

        $media->delete();

        return new ApiSuccessResponse(['deleted' => true]);
    }
}
