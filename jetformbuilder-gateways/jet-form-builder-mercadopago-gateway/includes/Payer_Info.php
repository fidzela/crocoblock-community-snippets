<?php
/**
 * ============================================================================
 *  Payer_Info — dados do PAGADOR a partir dos campos do formulário
 * ============================================================================
 *
 *  PARA QUE: melhorar a "pontuação da integração" do Mercado Pago (anti-fraude /
 *  aprovação) e povoar o pagador no admin (JFB → Payments). Captura nome,
 *  sobrenome, e-mail, telefone, CPF/CNPJ e endereço dos campos do form e:
 *    1) ENVIA ao MP no Pay Now (preference.payer + additional_info.payer) — via o
 *       filtro `jet-form-builder/mercadopago/preference` que o Create_Preference
 *       JÁ dispara, então NÃO tocamos no create-preference.php;
 *    2) VINCULA o pagador ao pagamento no admin (Payer_Model + Payer_Shipping +
 *       Payment_To_Payer_Shipping), resolvendo o "Payer: Not attached" do pay-now
 *       (que hoje só grava o Payer_Model, sem o vínculo que o Stripe cria).
 *
 *  MAPEAMENTO POR CONVENÇÃO DE NOME (o dono escolhe o NAME/ID do campo no form):
 *  cada dado tem uma lista de chaves aceitas (filtrável). Ex.: o e-mail é lido de
 *  um campo chamado `payer_email`, `email`, `e_mail`, `mail` ou `user_email`.
 *
 *  ESCOPO: o bloco "payer" rico vale para o PAY NOW (Preference). A Assinatura
 *  (Preapproval) só aceita `payer_email` na API do MP — já resolvido no
 *  subscription-logic; aqui mantemos a consistência do e-mail.
 *
 *  SEGURANÇA: roda server-side no submit; nada vai ao cliente. Best-effort: uma
 *  falha de enriquecimento NUNCA quebra o pagamento.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway;

use Jet_Form_Builder\Gateways\Db_Models\Payer_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payer_Shipping_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payment_To_Payer_Shipping_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payer_Info {

	/**
	 * Dado canônico -> nomes de campo aceitos no form (o 1º não-vazio vence).
	 * Filtrável em `jet-form-builder/mercadopago/payer-field-map`.
	 */
	const FIELD_MAP = array(
		'email'         => array( 'payer_email', 'email', 'e_mail', 'mail', 'user_email' ),
		'first_name'    => array( 'payer_first_name', 'first_name', 'payer_name', 'nome', 'primeiro_nome' ),
		'last_name'     => array( 'payer_last_name', 'last_name', 'sobrenome', 'surname' ),
		'full_name'     => array( 'payer_full_name', 'full_name', 'nome_completo', 'name' ),
		'area_code'     => array( 'payer_area_code', 'area_code', 'ddd' ),
		'phone'         => array( 'payer_phone', 'phone', 'telefone', 'celular', 'whatsapp', 'fone', 'tel' ),
		'identification'=> array( 'payer_cpf', 'cpf', 'payer_cnpj', 'cnpj', 'identification', 'documento', 'doc' ),
		'zip_code'      => array( 'payer_zip', 'zip_code', 'cep', 'postal_code', 'codigo_postal' ),
		'street_name'   => array( 'payer_street', 'street_name', 'endereco', 'rua', 'logradouro' ),
		'street_number' => array( 'payer_number', 'street_number', 'numero' ),
		'city'          => array( 'payer_city', 'city', 'cidade' ),
		'state'         => array( 'payer_state', 'state', 'estado', 'uf' ),
	);

	/**
	 * Liga o hook que injeta o payer na preference (Pay Now). Chamado no bootstrap.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter(
			'jet-form-builder/mercadopago/preference',
			array( __CLASS__, 'inject_into_preference' ),
			10,
			2
		);
	}

	/**
	 * Lê o request do form e resolve os dados canônicos do pagador.
	 *
	 * @return array Ex.: [ 'email'=>..., 'first_name'=>..., 'phone'=>..., ... ]
	 */
	public static function from_request(): array {
		$request = ( function_exists( 'jet_fb_action_handler' ) && jet_fb_action_handler() )
			? ( jet_fb_action_handler()->request_data ?? array() )
			: array();

		if ( ! is_array( $request ) ) {
			$request = array();
		}

		$map  = apply_filters( 'jet-form-builder/mercadopago/payer-field-map', self::FIELD_MAP );
		$data = array();

		foreach ( $map as $canonical => $candidates ) {
			foreach ( (array) $candidates as $key ) {
				if ( isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) && '' !== trim( (string) $request[ $key ] ) ) {
					$data[ $canonical ] = trim( (string) $request[ $key ] );
					break;
				}
			}
		}

		// Nome: se só veio "full_name" (ou só first), deriva first/last.
		if ( empty( $data['first_name'] ) && ! empty( $data['full_name'] ) ) {
			$parts              = preg_split( '/\s+/', $data['full_name'], 2 );
			$data['first_name'] = $parts[0] ?? '';
			if ( empty( $data['last_name'] ) && ! empty( $parts[1] ) ) {
				$data['last_name'] = $parts[1];
			}
		}

		return apply_filters( 'jet-form-builder/mercadopago/payer-data', $data, $request );
	}

	/**
	 * Hook do filtro: injeta payer + additional_info.payer na preference (Pay Now).
	 *
	 * @param array $preference
	 * @param mixed $action
	 *
	 * @return array
	 */
	public static function inject_into_preference( array $preference, $action = null ): array {
		$d = self::from_request();

		if ( empty( $d ) ) {
			return $preference;
		}

		$payer = array();

		if ( ! empty( $d['first_name'] ) ) {
			$payer['name'] = $d['first_name'];
		}
		if ( ! empty( $d['last_name'] ) ) {
			$payer['surname'] = $d['last_name'];
		}
		if ( ! empty( $d['email'] ) && is_email( $d['email'] ) ) {
			$payer['email'] = $d['email'];
		}

		$phone = self::phone_parts( $d );
		if ( $phone ) {
			$payer['phone'] = $phone;
		}

		$ident = self::identification_parts( $d );
		if ( $ident ) {
			$payer['identification'] = $ident;
		}

		$address = self::address_parts( $d );
		if ( $address ) {
			$payer['address'] = $address;
		}

		if ( empty( $payer ) ) {
			return $preference;
		}

		// Não sobrescreve o que já houver (merge defensivo).
		$preference['payer'] = array_merge( isset( $preference['payer'] ) && is_array( $preference['payer'] ) ? $preference['payer'] : array(), $payer );

		// additional_info.payer melhora a pontuação anti-fraude do MP.
		$ai = array();
		if ( ! empty( $d['first_name'] ) ) {
			$ai['first_name'] = $d['first_name'];
		}
		if ( ! empty( $d['last_name'] ) ) {
			$ai['last_name'] = $d['last_name'];
		}
		if ( $phone ) {
			$ai['phone'] = $phone;
		}
		if ( $address ) {
			$ai['address'] = $address;
		}

		if ( $ai ) {
			$preference['additional_info'] = isset( $preference['additional_info'] ) && is_array( $preference['additional_info'] ) ? $preference['additional_info'] : array();
			$preference['additional_info']['payer'] = array_merge(
				isset( $preference['additional_info']['payer'] ) && is_array( $preference['additional_info']['payer'] ) ? $preference['additional_info']['payer'] : array(),
				$ai
			);
		}

		return $preference;
	}

	/**
	 * Vincula o pagador ao pagamento no admin com os dados que o MERCADO PAGO
	 * devolve (payment.payer) — PARIDADE com a assinatura (recorder): cria
	 * Payer_Model + Payer_Shipping + Payment_To_Payer_Shipping. É ESSA cadeia que a
	 * coluna "Payer" de JFB → Payments resolve (sem ela = "Payer: Not attached").
	 *
	 * Os dados que o dono mapeia no FORM já vão ao MP por inject_into_preference e
	 * VOLTAM aqui no `payer` — então um único ponto (a confirmação) cobre tudo, sem
	 * depender de campos do form. Em produção o MP devolve e-mail/nome do pagador
	 * real (inclusive no Pix).
	 *
	 * Idempotência prática: só o VENCEDOR da confirmação chega aqui — o retorno do
	 * navegador OU o webhook (a transição atômica CREATED->COMPLETED serializa).
	 *
	 * @param int   $payment_id Id interno do Payment_Model.
	 * @param int   $user_id
	 * @param array $mp_payer   O objeto `payer` do payment do MP (email/first_name/last_name/id).
	 *
	 * @return void
	 */
	public static function attach_from_mp( int $payment_id, int $user_id, array $mp_payer ) {
		if ( $payment_id <= 0 || empty( $mp_payer['email'] ) ) {
			return;
		}

		$first = (string) ( $mp_payer['first_name'] ?? '' );
		$last  = (string) ( $mp_payer['last_name'] ?? '' );

		try {
			$payer_id = Payer_Model::insert_or_update(
				array(
					'user_id'    => $user_id,
					'payer_id'   => (string) ( $mp_payer['id'] ?? '' ),
					'first_name' => '' !== $first ? $first : null,
					'last_name'  => '' !== $last ? $last : null,
					'email'      => (string) $mp_payer['email'],
				)
			);

			$payer_ship_id = ( new Payer_Shipping_Model() )->insert(
				array(
					'payer_id'  => $payer_id,
					'full_name' => trim( $first . ' ' . $last ),
				)
			);

			( new Payment_To_Payer_Shipping_Model() )->insert(
				array(
					'payment_id'        => $payment_id,
					'payer_shipping_id' => $payer_ship_id,
				)
			);
		} catch ( \Throwable $e ) {
			// Best-effort: o enriquecimento do pagador não pode derrubar a venda.
			return;
		}
	}

	/**
	 * Telefone no formato do MP: { area_code, number }. Aceita campos separados
	 * (area_code + phone) ou um campo único (ex.: "(11) 99999-8888" -> DDD+resto).
	 *
	 * @param array $d
	 *
	 * @return array|null
	 */
	private static function phone_parts( array $d ) {
		$raw_phone = isset( $d['phone'] ) ? preg_replace( '/\D+/', '', $d['phone'] ) : '';
		$area      = isset( $d['area_code'] ) ? preg_replace( '/\D+/', '', $d['area_code'] ) : '';

		if ( '' === $raw_phone && '' === $area ) {
			return null;
		}

		// Sem DDD separado: se o telefone tem DDD embutido (>= 10 dígitos), separa.
		if ( '' === $area && strlen( $raw_phone ) >= 10 ) {
			$area      = substr( $raw_phone, 0, 2 );
			$raw_phone = substr( $raw_phone, 2 );
		}

		$out = array();
		if ( '' !== $area ) {
			$out['area_code'] = $area;
		}
		if ( '' !== $raw_phone ) {
			$out['number'] = $raw_phone;
		}

		return $out ?: null;
	}

	/**
	 * Identificação (CPF/CNPJ) no formato do MP: { type, number }. Detecta o tipo
	 * pela quantidade de dígitos (11=CPF, 14=CNPJ).
	 *
	 * @param array $d
	 *
	 * @return array|null
	 */
	private static function identification_parts( array $d ) {
		if ( empty( $d['identification'] ) ) {
			return null;
		}

		$digits = preg_replace( '/\D+/', '', $d['identification'] );

		if ( '' === $digits ) {
			return null;
		}

		$type = ( 14 === strlen( $digits ) ) ? 'CNPJ' : 'CPF';

		return array(
			'type'   => $type,
			'number' => $digits,
		);
	}

	/**
	 * Endereço no formato do MP: { zip_code, street_name, street_number }.
	 *
	 * @param array $d
	 *
	 * @return array|null
	 */
	private static function address_parts( array $d ) {
		$out = array();

		if ( ! empty( $d['zip_code'] ) ) {
			$out['zip_code'] = preg_replace( '/\D+/', '', $d['zip_code'] );
		}
		if ( ! empty( $d['street_name'] ) ) {
			$out['street_name'] = $d['street_name'];
		}
		if ( ! empty( $d['street_number'] ) ) {
			$out['street_number'] = $d['street_number'];
		}

		return $out ?: null;
	}
}
