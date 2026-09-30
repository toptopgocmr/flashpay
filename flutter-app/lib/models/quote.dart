/// Devis retourné par POST /api/pay/quote.
class FpQuoteParty {
  final String type; // wallet | mobile
  final String? name;
  final String? phone;
  final String country;
  final String? countryName;
  final String? flag;
  final String currency;
  final String? operator;
  /// Titulaire du compte mobile money vérifié chez l'opérateur (Verify Wallet PEEX).
  final String? verifiedName;

  FpQuoteParty({
    required this.type,
    this.name,
    this.phone,
    required this.country,
    this.countryName,
    this.flag,
    required this.currency,
    this.operator,
    this.verifiedName,
  });

  factory FpQuoteParty.fromJson(Map<String, dynamic> j) => FpQuoteParty(
        type: (j['type'] ?? 'mobile') as String,
        name: j['name'] as String?,
        phone: j['phone'] as String?,
        country: (j['country'] ?? '') as String,
        countryName: j['country_name'] as String?,
        flag: j['flag'] as String?,
        currency: (j['currency'] ?? 'XAF') as String,
        operator: j['operator'] as String?,
        verifiedName: j['verified_name'] as String?,
      );

  String get label {
    if (type == 'wallet') return 'FlashPay${name != null ? ' · $name' : ''}';
    if (type == 'card') return operator ?? 'Carte Visa / Mastercard';
    return [operator ?? 'Mobile money', phone].whereType<String>().join(' · ');
  }
}

class FpQuote {
  final String operation;
  final String scope; // national | regional | international
  final FpQuoteParty source;
  final FpQuoteParty destination;
  final int amount;
  final String currency;
  final int fee;
  final int total;
  final int receiveAmount;
  final String receiveCurrency;
  final int merchantFee;
  final double fxRate;
  final bool available;
  final List<String> problems;
  final bool isAsync;

  FpQuote({
    required this.operation,
    required this.scope,
    required this.source,
    required this.destination,
    required this.amount,
    required this.currency,
    required this.fee,
    required this.total,
    required this.receiveAmount,
    required this.receiveCurrency,
    required this.merchantFee,
    required this.fxRate,
    required this.available,
    required this.problems,
    required this.isAsync,
  });

  factory FpQuote.fromJson(Map<String, dynamic> j) => FpQuote(
        operation: j['operation'] as String,
        scope: (j['scope'] ?? 'national') as String,
        source: FpQuoteParty.fromJson(j['source'] as Map<String, dynamic>),
        destination: FpQuoteParty.fromJson(j['destination'] as Map<String, dynamic>),
        amount: (j['amount'] as num).toInt(),
        currency: j['currency'] as String,
        fee: (j['fee'] as num).toInt(),
        total: (j['total'] as num).toInt(),
        receiveAmount: (j['receive_amount'] as num).toInt(),
        receiveCurrency: j['receive_currency'] as String,
        merchantFee: ((j['merchant_fee'] ?? 0) as num).toInt(),
        fxRate: (((j['fx'] as Map?)?['rate'] ?? 1) as num).toDouble(),
        available: j['available'] == true,
        problems: ((j['problems'] ?? []) as List).map((e) => e.toString()).toList(),
        isAsync: j['async'] == true,
      );

  bool get converted => currency != receiveCurrency;

  String get scopeLabel => switch (scope) {
        'regional' => 'Sous-région',
        'international' => 'International',
        _ => 'National',
      };
}

/// Suivi d'une opération (GET /api/pay/transactions/{id}/status).
class FpPaymentStatus {
  final int id;
  final String reference;
  final String status; // processing | successful | failed | reversed
  final String? stage;
  final int amount;
  final int fee;
  final String currency;
  final int destinationAmount;
  final String destinationCurrency;
  final String message;
  final String? sourceAccount;
  final String? destinationAccount;
  final String? checkoutUrl; // page de paiement carte (3-D Secure) à ouvrir

  FpPaymentStatus({
    required this.id,
    required this.reference,
    required this.status,
    this.stage,
    required this.amount,
    required this.fee,
    required this.currency,
    required this.destinationAmount,
    required this.destinationCurrency,
    required this.message,
    this.sourceAccount,
    this.destinationAccount,
    this.checkoutUrl,
  });

  factory FpPaymentStatus.fromJson(Map<String, dynamic> j) => FpPaymentStatus(
        id: (j['id'] as num).toInt(),
        reference: (j['reference'] ?? '') as String,
        status: (j['status'] ?? 'processing') as String,
        stage: j['stage'] as String?,
        amount: ((j['amount'] ?? 0) as num).toInt(),
        fee: ((j['fee'] ?? 0) as num).toInt(),
        currency: (j['currency'] ?? 'XAF') as String,
        destinationAmount: ((j['destination_amount'] ?? j['amount'] ?? 0) as num).toInt(),
        destinationCurrency: (j['destination_currency'] ?? j['currency'] ?? 'XAF') as String,
        message: (j['message'] ?? j['failure_reason'] ?? '') as String,
        sourceAccount: j['source_account'] as String?,
        destinationAccount: j['destination_account'] as String?,
        checkoutUrl: j['checkout_url'] as String?,
      );

  bool get isPending => status == 'processing';
  bool get isSuccess => status == 'successful';
  bool get awaitingUssd => isPending && stage == 'awaiting_source';
  bool get awaitingCard => isPending && stage == 'awaiting_card';
}
