import '../models/quote.dart';
import 'api_client.dart';

/// API de l'espace Agent : tableau de bord, dépôt d'espèces, retrait cash, historique.
class AgentService {
  final _client = ApiClient();

  Future<Map<String, dynamic>> dashboard() async {
    final res = await _client.dio.get('/agent/dashboard');
    return Map<String, dynamic>.from(res.data as Map);
  }

  /// Dépôt : le client est identifié par son code QR FlashPay ou par son numéro.
  /// Par numéro, le client confirme d'abord le montant avec le code reçu par
  /// SMS (§3.1.3) : l'API répond 202 → [ClientConfirmationRequired].
  Future<FpPaymentStatus> cashIn({String? clientCode, String? clientPhone, required int amount, String? otp}) async {
    final res = await _client.dio.post('/agent/cash-in', data: {
      if (clientCode != null) 'client_code': clientCode,
      if (clientPhone != null) 'client_phone': clientPhone,
      'amount': amount,
      if (otp != null) 'otp': otp,
    });
    if (res.statusCode == 202 && res.data is Map && res.data['confirmation_required'] == true) {
      throw ClientConfirmationRequired(res.data['message']?.toString() ?? 'Code client requis', res.data['client_name']?.toString(), res.data['debug_code']?.toString());
    }
    return FpPaymentStatus.fromJson(Map<String, dynamic>.from(res.data as Map));
  }

  /// Vérifie un code de retrait avant de remettre les espèces.
  Future<Map<String, dynamic>> voucher(String code) async {
    final res = await _client.dio.get('/agent/vouchers/${Uri.encodeComponent(code)}');
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<Map<String, dynamic>> redeem(String code) async {
    final res = await _client.dio.post('/agent/vouchers/redeem', data: {'code': code});
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<List<Map<String, dynamic>>> history({int page = 1}) async {
    final res = await _client.dio.get('/agent/history', queryParameters: {'page': page});
    return ((res.data['data'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  }
}

class ClientConfirmationRequired implements Exception {
  final String message;
  final String? clientName;
  final String? debugCode;
  ClientConfirmationRequired(this.message, this.clientName, this.debugCode);
  @override
  String toString() => message;
}

/// flashpay://code?c=… (code client) ou flashpay://cashout?c=… (bon de retrait) ou chiffres saisis.
String? fpExtractCode(String raw, {required String host, required int length}) {
  final uri = Uri.tryParse(raw.trim());
  if (uri != null && uri.scheme == 'flashpay' && uri.host == host) return uri.queryParameters['c'];
  final digits = raw.replaceAll(RegExp(r'\D'), '');
  return digits.length == length ? digits : null;
}
