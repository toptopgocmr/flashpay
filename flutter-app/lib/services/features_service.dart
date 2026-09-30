import 'package:dio/dio.dart';
import 'api_client.dart';

Map<String, dynamic> _map(dynamic d) => Map<String, dynamic>.from(d as Map);
List<Map<String, dynamic>> _list(dynamic d) => ((d ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();

/// API des fonctionnalités du cahier des charges v1.5 (notifications, KYC,
/// sécurité, comptes liés, cadeaux, partage de note, mini-programmes,
/// support / litiges, QR dynamique, e-commerce dans l'app).
class FeaturesService {
  final Dio _dio = ApiClient().dio;

  // ------------------------------------------------ Notifications (§11)
  Future<Map<String, dynamic>> notifications({int page = 1}) async => _map((await _dio.get('/notifications', queryParameters: {'page': page})).data);
  Future<void> markAllRead() => _dio.post('/notifications/read-all');
  Future<void> markRead(int id) => _dio.post('/notifications/$id/read');

  // ------------------------------------------------ KYC & plafonds (§12)
  Future<Map<String, dynamic>> kyc() async => _map((await _dio.get('/kyc')).data);
  /// Envoi d'une pièce KYC. [bytes] (recommandé) fonctionne partout, y compris
  /// dans le navigateur ; [filePath] seul ne marche que sur Android / iOS.
  Future<void> uploadKyc({required String type, String filePath = '', List<int>? bytes, String? filename, String? idNumber}) async {
    var name = (filename == null || filename.isEmpty) ? filePath.split('/').last : filename;
    if (!RegExp(r'\.(jpe?g|png|pdf)$', caseSensitive: false).hasMatch(name)) name = 'kyc_$type.jpg';
    final form = FormData.fromMap({
      'type': type,
      'file': bytes != null ? MultipartFile.fromBytes(bytes, filename: name) : await MultipartFile.fromFile(filePath, filename: name),
      if (idNumber != null && idNumber.isNotEmpty) 'id_number': idNumber,
    });
    await _dio.post('/kyc/documents', data: form);
  }

  // ------------------------------------------------ Sécurité (§4.5, §15)
  Future<void> setPin({required String pin, String? currentPin, String? password}) => _dio.post('/me/pin', data: {
        'pin': pin,
        if (currentPin != null) 'current_pin': currentPin,
        if (password != null) 'password': password,
      });
  Future<List<Map<String, dynamic>>> devices() async => _list((await _dio.get('/me/devices')).data);
  Future<void> revokeDevice(int id) => _dio.delete('/me/devices/$id');
  Future<void> setLanguage(String lang) => _dio.patch('/me', data: {'language': lang});
  Future<Map<String, dynamic>> status() async => _map((await _dio.get('/status')).data);

  // ------------------------------------------------ Wallet & comptes liés (§3.3.5)
  Future<Map<String, dynamic>> overview() async => _map((await _dio.get('/wallet/overview')).data);
  Future<List<Map<String, dynamic>>> linkedAccounts() async => _list((await _dio.get('/linked-accounts')).data);
  Future<void> addLinkedAccount(Map<String, dynamic> data) => _dio.post('/linked-accounts', data: data);
  Future<void> deleteLinkedAccount(int id) => _dio.delete('/linked-accounts/$id');
  /// Compte lié débité par défaut pour son type (mobile money, carte, banque).
  Future<void> setDefaultLinkedAccount(int id) => _dio.post('/linked-accounts/$id/default');
  Future<Map<String, dynamic>> withdrawToBank({required int accountId, required int amount}) async =>
      _map((await _dio.post('/pay/withdraw-bank', data: {'linked_account_id': accountId, 'amount': amount})).data);

  // ------------------------------------------------ Cadeaux d'argent (§3.5.1)
  Future<Map<String, dynamic>> gifts() async => _map((await _dio.get('/gifts')).data);
  Future<Map<String, dynamic>> sendGift(Map<String, dynamic> data) async => _map((await _dio.post('/gifts', data: data)).data);
  Future<Map<String, dynamic>> claimGift(String code) async => _map((await _dio.post('/gifts/${Uri.encodeComponent(code)}/claim')).data);

  // ------------------------------------------------ Partage de note (§3.5.2)
  Future<Map<String, dynamic>> splits() async => _map((await _dio.get('/splits')).data);
  Future<Map<String, dynamic>> createSplit(Map<String, dynamic> data) async => _map((await _dio.post('/splits', data: data)).data);
  Future<void> paySplitShare(int shareId) => _dio.post('/splits/shares/$shareId/pay');
  Future<void> declineSplitShare(int shareId) => _dio.post('/splits/shares/$shareId/decline');
  Future<int> remindSplit(int splitId) async => ((await _dio.post('/splits/$splitId/remind')).data['reminded'] as num).toInt();

  // ------------------------------------------------ Demandes d'argent (app v2)
  Future<Map<String, dynamic>> moneyRequests() async => _map((await _dio.get('/money-requests')).data);
  Future<Map<String, dynamic>> moneyRequest(int id) async => _map((await _dio.get('/money-requests/$id')).data);
  Future<Map<String, dynamic>> createMoneyRequest({required String phone, required int amount, String? note}) async =>
      _map((await _dio.post('/money-requests', data: {'phone': phone, 'amount': amount, if (note != null && note.isNotEmpty) 'note': note})).data);
  Future<void> payMoneyRequest(int id) => _dio.post('/money-requests/$id/pay');
  Future<void> declineMoneyRequest(int id) => _dio.post('/money-requests/$id/decline');
  Future<void> cancelMoneyRequest(int id) => _dio.post('/money-requests/$id/cancel');
  Future<void> remindMoneyRequest(int id) => _dio.post('/money-requests/$id/remind');

  // ------------------------------------------------ Mini-programmes (§3.5.3)
  Future<Map<String, dynamic>> miniPrograms({String? category}) async =>
      _map((await _dio.get('/mini-programs', queryParameters: {if (category != null) 'category': category})).data);

  // ------------------------------------------------ Support & litiges (§13.2, §16)
  Future<Map<String, dynamic>> faq() async => _map((await _dio.get('/support/faq')).data);
  Future<List<Map<String, dynamic>>> tickets() async => _list((await _dio.get('/support/tickets')).data);
  Future<void> openTicket({required String category, required String subject, required String message}) =>
      _dio.post('/support/tickets', data: {'category': category, 'subject': subject, 'message': message});
  Future<void> replyTicket(int id, String message) => _dio.post('/support/tickets/$id/messages', data: {'message': message});
  Future<List<Map<String, dynamic>>> disputes() async => _list((await _dio.get('/disputes')).data);
  Future<Map<String, dynamic>> openDispute({required int transactionId, required String reason, String? description}) async =>
      _map((await _dio.post('/disputes', data: {'transaction_id': transactionId, 'reason': reason, if (description != null) 'description': description})).data);

  // ------------------------------------------------ QR dynamique / liens / e-commerce (payeur)
  Future<Map<String, dynamic>> paymentRequest(String token, {String? sig}) async =>
      _map((await _dio.get('/pay/requests/$token', queryParameters: {if (sig != null) 's': sig})).data);
  Future<Map<String, dynamic>> payRequest(String token, {String? sig}) async =>
      _map((await _dio.post('/pay/requests/$token', data: {if (sig != null) 's': sig})).data);
  Future<List<Map<String, dynamic>>> pendingIntents() async => _list((await _dio.get('/pay/intents')).data);
  Future<Map<String, dynamic>> intent(String id) async => _map((await _dio.get('/pay/intents/$id')).data);
  Future<Map<String, dynamic>> payIntent(String id) async => _map((await _dio.post('/pay/intents/$id')).data);
}

/// Libellés des motifs de contestation (§13.2).
const Map<String, String> kDisputeReasons = {
  'unrecognized': 'Paiement non reconnu',
  'wrong_amount': 'Montant erroné',
  'cash_out_not_received': 'Cash-out non reçu',
  'cash_in_not_credited': 'Dépôt non crédité',
  'merchant_not_delivered': 'Bien / service non fourni',
  'other': 'Autre',
};
