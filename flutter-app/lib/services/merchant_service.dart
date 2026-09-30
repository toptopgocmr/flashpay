import 'api_client.dart';

class MerchantService {
  final _client = ApiClient();

  Future<Map<String, dynamic>> dashboard() async {
    final res = await _client.dio.get('/merchant/dashboard');
    return res.data;
  }

  Future<List<dynamic>> outlets() async {
    final res = await _client.dio.get('/merchant/outlets');
    return res.data as List<dynamic>;
  }

  Future<void> createOutlet({required String name, String? address}) async {
    await _client.dio.post('/merchant/outlets', data: {'name': name, 'address': address});
  }

  Future<Map<String, dynamic>> collections({int page = 1}) async {
    final res = await _client.dio.get('/merchant/collections', queryParameters: {'page': page});
    return res.data;
  }

  Future<Map<String, dynamic>> requestWithdrawal({
    required int amount,
    required String destinationRail, // toujours 'peex' (seule passerelle)
    required String destinationAccount,
  }) async {
    final res = await _client.dio.post('/merchant/withdraw', data: {
      'amount': amount,
      'destination_rail': destinationRail,
      'destination_account': destinationAccount,
    });
    return res.data;
  }
}
