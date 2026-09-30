import '../models/transaction.dart';
import 'api_client.dart';

class TransactionService {
  final _client = ApiClient();

  Future<List<FpTransaction>> history({int page = 1}) async {
    final res = await _client.dio.get('/transactions', queryParameters: {'page': page});
    final list = (res.data['data'] as List).cast<Map<String, dynamic>>();
    return list.map(FpTransaction.fromJson).toList();
  }

  Future<Map<String, dynamic>> detail(int id) async {
    final res = await _client.dio.get('/transactions/$id');
    return res.data;
  }

  /// Envoi d'argent P2P (client -> client ou client -> hors réseau via PEEX).
  Future<FpTransaction> sendMoney({required String destinationPhone, required int amount}) async {
    final res = await _client.dio.post('/transactions/send-money', data: {
      'destination_phone': destinationPhone,
      'amount': amount,
    });
    return FpTransaction.fromJson(res.data);
  }

  /// Paiement marchand par QR / NFC / saisie manuelle.
  Future<FpTransaction> payMerchant({
    String? qrCodeToken,
    int? merchantId,
    required int amount,
    required String method, // 'qr' | 'nfc' | 'manual'
  }) async {
    final res = await _client.dio.post('/transactions/pay-merchant', data: {
      if (qrCodeToken != null) 'qr_code_token': qrCodeToken,
      if (merchantId != null) 'merchant_id': merchantId,
      'amount': amount,
      'method': method,
    });
    return FpTransaction.fromJson(res.data);
  }
}
