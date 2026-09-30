import 'api_client.dart';

/// Règlements marchand : comptes (mobile money, banque, wallet FlashPay, cash agent),
/// règlement vers un compte, règlement automatique, historique.
class SettlementService {
  final _client = ApiClient();

  Future<Map<String, dynamic>> overview() async {
    final res = await _client.dio.get('/merchant/settlement');
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<Map<String, dynamic>> addAccount(Map<String, dynamic> data) async {
    final res = await _client.dio.post('/merchant/settlement/accounts', data: data);
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<void> setDefault(int id) => _client.dio.post('/merchant/settlement/accounts/$id/default');

  Future<void> delete(int id) => _client.dio.delete('/merchant/settlement/accounts/$id');

  Future<Map<String, dynamic>> settle({required int accountId, required int amount}) async {
    final res = await _client.dio.post('/merchant/settlement/settle', data: {'account_id': accountId, 'amount': amount});
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<void> auto({required String mode, required int min}) =>
      _client.dio.post('/merchant/settlement/auto', data: {'mode': mode, 'min': min});
}
