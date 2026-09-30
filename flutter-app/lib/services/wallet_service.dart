import '../models/user.dart';
import 'api_client.dart';

class WalletService {
  final _client = ApiClient();

  Future<FpWallet> getWallet() async {
    final res = await _client.dio.get('/wallet');
    return FpWallet.fromJson(res.data);
  }

  Future<List<dynamic>> getRates() async {
    final res = await _client.dio.get('/rates');
    return res.data as List<dynamic>;
  }
}
