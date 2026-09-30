import '../models/corridor.dart';
import '../models/payment_method.dart';
import '../models/quote.dart';
import 'api_client.dart';

/// Paiements interopérables (API /api/pay/*) : envoyer, payer un marchand,
/// recharger, retirer — vers et depuis MTN, Airtel, Orange, Moov… (via PEEX).
class PaymentService {
  final _client = ApiClient();
  static List<FpCountry>? _countries;

  Future<List<FpCountry>> countries({bool refresh = false}) async {
    if (_countries != null && !refresh) return _countries!;
    final res = await _client.dio.get('/pay/corridors');
    _countries = ((res.data['corridors'] as List).cast<Map<String, dynamic>>()).map(FpCountry.fromJson).toList();
    return _countries!;
  }

  Future<Map<String, dynamic>> lookup(String phone, {String? country}) async {
    final res = await _client.dio.post('/pay/lookup', data: {'phone': phone, if (country != null) 'country': country});
    return Map<String, dynamic>.from(res.data as Map);
  }

  /// [operation] : transfer | deposit | withdraw | merchant
  Future<FpQuote> quote({
    required String operation,
    required int amount,
    String? source, // wallet | mobile
    String? sourcePhone,
    String? destinationPhone,
    String? merchantCode,
    String deliverTo = 'auto',
  }) async {
    final res = await _client.dio.post('/pay/quote', data: {
      'operation': operation,
      'amount': amount,
      if (source != null) 'source': source,
      if (sourcePhone != null) 'source_phone': sourcePhone,
      if (destinationPhone != null) 'destination_phone': destinationPhone,
      if (merchantCode != null) 'merchant_code': merchantCode,
      'deliver_to': deliverTo,
    });
    return FpQuote.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<FpPaymentStatus> transfer({
    required String source,
    String? sourcePhone,
    required String destinationPhone,
    required int amount,
    String? beneficiaryName,
    String? note,
    String deliverTo = 'auto',
  }) async {
    final res = await _client.dio.post('/pay/transfer', data: {
      'source': source,
      if (sourcePhone != null) 'source_phone': sourcePhone,
      'destination_phone': destinationPhone,
      'amount': amount,
      if (beneficiaryName != null && beneficiaryName.isNotEmpty) 'beneficiary_name': beneficiaryName,
      if (note != null && note.isNotEmpty) 'note': note,
      'deliver_to': deliverTo,
    });
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<FpPaymentStatus> deposit({required String phone, required int amount}) async {
    final res = await _client.dio.post('/pay/deposit', data: {'phone': phone, 'amount': amount});
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  /// Recharge par carte Visa / Mastercard : renvoie l'URL de la page de paiement sécurisée.
  Future<FpPaymentStatus> cardDeposit({required int amount}) async {
    final res = await _client.dio.post('/pay/deposit', data: {'method': 'card', 'amount': amount});
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<FpPaymentStatus> withdraw({required String phone, required int amount}) async {
    final res = await _client.dio.post('/pay/withdraw', data: {'phone': phone, 'amount': amount});
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<Map<String, dynamic>> merchantInfo(String code) async {
    final res = await _client.dio.get('/pay/merchant/${Uri.encodeComponent(code)}');
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<FpPaymentStatus> payMerchant({
    required String merchantCode,
    required String source,
    String? sourcePhone,
    required int amount,
    String method = 'qr', // qr | nfc
  }) async {
    final res = await _client.dio.post('/pay/merchant', data: {
      'merchant_code': merchantCode,
      'source': source,
      if (sourcePhone != null) 'source_phone': sourcePhone,
      'amount': amount,
      'method': method,
    });
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<FpPaymentStatus> status(int transactionId) async {
    final res = await _client.dio.get('/pay/transactions/$transactionId/status');
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  // ---- Moyens par pays, code de paiement QR, bons de retrait

  /// [operation] : deposit | withdraw | pay
  Future<FpMethods> methods({required String operation, String? country}) async {
    final res = await _client.dio.get('/pay/methods', queryParameters: {'operation': operation, if (country != null) 'country': country});
    return FpMethods.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<FpPayCode> payCode() async {
    final res = await _client.dio.post('/pay/code');
    return FpPayCode.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  /// [channel] : cash_pickup | atm
  Future<FpVoucher> createVoucher({
    required String channel,
    required int amount,
    required String country,
    String? beneficiaryName,
    String? beneficiaryPhone,
  }) async {
    final res = await _client.dio.post('/pay/vouchers', data: {
      'channel': channel,
      'amount': amount,
      'country': country,
      if (beneficiaryName != null && beneficiaryName.isNotEmpty) 'beneficiary_name': beneficiaryName,
      if (beneficiaryPhone != null && beneficiaryPhone.isNotEmpty) 'beneficiary_phone': beneficiaryPhone,
    });
    return FpVoucher.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<List<FpVoucher>> vouchers() async {
    final res = await _client.dio.get('/pay/vouchers');
    return (res.data as List).map((e) => FpVoucher.fromJson(Map<String, dynamic>.from(e as Map))).toList();
  }

  Future<FpVoucher> cancelVoucher(int id) async {
    final res = await _client.dio.post('/pay/vouchers/$id/cancel');
    return FpVoucher.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  // ---- Marchand

  /// Encaisse le code de paiement présenté par le client (QR scanné).
  Future<FpPaymentStatus> merchantChargeCode({required String code, required int amount}) async {
    final res = await _client.dio.post('/merchant/charge-code', data: {'code': code, 'amount': amount});
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<FpPaymentStatus> merchantCollectUssd({required String customerPhone, required int amount, String? customerName}) async {
    final res = await _client.dio.post('/merchant/collect-ussd', data: {
      'customer_phone': customerPhone,
      'amount': amount,
      if (customerName != null && customerName.isNotEmpty) 'customer_name': customerName,
    });
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  Future<Map<String, dynamic>> merchantQr() async {
    final res = await _client.dio.get('/merchant/qr');
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<FpPaymentStatus> merchantWithdraw({required String phone, required int amount}) async {
    final res = await _client.dio.post('/merchant/withdraw', data: {'destination_account': phone, 'amount': amount});
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }
}

/// Mise en forme des montants : 12 500 XAF
String fpMoney(num amount, [String currency = 'XAF']) {
  final s = amount.round().toString().replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?!\d))'), (m) => '${m[1]} ');
  return '$s $currency';
}
