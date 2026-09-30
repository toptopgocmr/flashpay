import 'package:dio/dio.dart';
import 'api_client.dart';

Map<String, dynamic> _map(dynamic d) => Map<String, dynamic>.from(d as Map);
List<Map<String, dynamic>> _list(dynamic d) => ((d ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();

/// Outils marchand et caissier (§3.2) : QR dynamique, liens de paiement,
/// caissiers, remboursements, rapports, paiement en ligne.
class MerchantToolsService {
  final Dio _dio = ApiClient().dio;

  /// [kind] : dynamic_qr | payment_link | nfc
  Future<Map<String, dynamic>> createRequest({required int amount, String kind = 'dynamic_qr', String? description, String? customerPhone, int? expiresInHours}) async =>
      _map((await _dio.post('/merchant/payment-requests', data: {
        'amount': amount,
        'kind': kind,
        if (description != null && description.isNotEmpty) 'description': description,
        if (customerPhone != null && customerPhone.isNotEmpty) 'customer_phone': customerPhone,
        if (expiresInHours != null) 'expires_in_hours': expiresInHours,
      })).data);
  Future<Map<String, dynamic>> request(String token) async => _map((await _dio.get('/merchant/payment-requests/$token')).data);
  Future<List<Map<String, dynamic>>> requests({String? kind}) async =>
      _list((await _dio.get('/merchant/payment-requests', queryParameters: {if (kind != null) 'kind': kind})).data['data']);
  Future<void> cancelRequest(String token) => _dio.post('/merchant/payment-requests/$token/cancel');

  Future<Map<String, dynamic>> cashierCollections() async => _map((await _dio.get('/merchant/cashier/collections')).data);

  Future<List<Map<String, dynamic>>> cashiers() async => _list((await _dio.get('/merchant/cashiers')).data);
  Future<void> createCashier({required String name, required String phone, required String password, int? outletId}) =>
      _dio.post('/merchant/cashiers', data: {'full_name': name, 'phone': phone, 'password': password, if (outletId != null) 'outlet_id': outletId});
  Future<void> updateCashier(int id, String action, {String? reason}) => _dio.post('/merchant/cashiers/$id', data: {'action': action, if (reason != null) 'reason': reason});

  Future<void> refund({required int transactionId, int? amount, String? reason}) =>
      _dio.post('/merchant/refunds', data: {'transaction_id': transactionId, if (amount != null) 'amount': amount, if (reason != null) 'reason': reason});

  Future<Map<String, dynamic>> reports({String? from, String? to}) async =>
      _map((await _dio.get('/merchant/reports', queryParameters: {if (from != null) 'from': from, if (to != null) 'to': to})).data);
  String statementUrl() => '${_dio.options.baseUrl}/merchant/statement';

  Future<Map<String, dynamic>> apiKeys() async => _map((await _dio.get('/merchant/api-keys')).data);
  Future<Map<String, dynamic>> issueApiKeys({required String environment, String? webhookUrl}) async =>
      _map((await _dio.post('/merchant/api-keys', data: {'environment': environment, if (webhookUrl != null && webhookUrl.isNotEmpty) 'webhook_url': webhookUrl})).data);
}

/// Espace agent (§3.1) : approvisionnement, caisse, journal, rapprochement.
class AgentFloatService {
  final Dio _dio = ApiClient().dio;

  Future<Map<String, dynamic>> floatRequests() async => _map((await _dio.get('/agent/float-requests')).data);
  Future<void> createFloatRequest({required int amount, required String method, String? proof, String? superAgentCode, String? note}) =>
      _dio.post('/agent/float-requests', data: {
        'amount': amount,
        'method': method,
        if (proof != null && proof.isNotEmpty) 'proof_reference': proof,
        if (superAgentCode != null && superAgentCode.isNotEmpty) 'super_agent_code': superAgentCode,
        if (note != null && note.isNotEmpty) 'note': note,
      });
  Future<void> cancelFloatRequest(int id) => _dio.post('/agent/float-requests/$id/cancel');
  Future<void> reviewFloatRequest(int id, {required bool approve, String? reason}) =>
      _dio.post('/agent/float-requests/$id/review', data: {'decision': approve ? 'approve' : 'reject', if (reason != null) 'reason': reason});

  Future<Map<String, dynamic>> float() async => _map((await _dio.get('/agent/float')).data);
  Future<List<Map<String, dynamic>>> journal({String? type}) async =>
      _list((await _dio.get('/agent/journal', queryParameters: {if (type != null) 'type': type})).data['data']);
  Future<Map<String, dynamic>> reconciliation({String? date}) async =>
      _map((await _dio.get('/agent/reconciliation', queryParameters: {if (date != null) 'date': date})).data);
  Future<Map<String, dynamic>> commissions() async => _map((await _dio.get('/agent/commissions')).data);
}
