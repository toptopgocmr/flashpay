import 'package:flutter/foundation.dart';
import '../models/user.dart';
import 'api_client.dart';

/// Connexion depuis un nouvel appareil : l'API exige un code OTP (§15).
class OtpRequiredException implements Exception {
  final String message;
  final String? debugCode; // renvoyé uniquement en sandbox
  OtpRequiredException(this.message, [this.debugCode]);
  @override
  String toString() => message;
}

class AuthService {
  final _client = ApiClient();

  Future<Map<String, dynamic>> _deviceInfo() async => {
        'device_id': await _client.deviceId(),
        'device_name': kIsWeb ? 'Navigateur' : defaultTargetPlatform.name,
        'platform': kIsWeb ? 'web' : (defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android'),
        // NFC HCE uniquement sous Android (§4.4) ; iOS bascule sur le QR
        'nfc_hce': !kIsWeb && defaultTargetPlatform == TargetPlatform.android,
      };

  /// Connexion — [profile] détermine quel espace ouvrir
  /// ('client' | 'merchant' | 'agent' | 'cashier').
  Future<FpUser> login({required String phone, required String password, required String profile, String? otp}) async {
    final res = await _client.dio.post('/auth/login', data: {
      'phone': phone,
      'password': password,
      'profile': profile,
      if (otp != null) 'otp': otp,
      ...await _deviceInfo(),
    });
    if (res.statusCode == 202 && res.data['otp_required'] == true) {
      throw OtpRequiredException(res.data['message']?.toString() ?? 'Code SMS requis', res.data['debug_code']?.toString());
    }
    await _client.saveToken(res.data['token']);
    return FpUser.fromJson(res.data['user']);
  }

  /// Envoie un code OTP par SMS. [purpose] : register | pin_reset | login_device.
  Future<String?> requestOtp(String phone, String purpose) async {
    final res = await _client.dio.post('/auth/otp', data: {'phone': phone, 'purpose': purpose});
    return res.data['debug_code']?.toString();
  }

  Future<FpUser> register({
    required String fullName,
    required String phone,
    required String password,
    required String profile, // 'client' | 'merchant'
    String? businessName,
    String? businessCategory,
    String? address,
    String? pin,
    String? otp,
    String? dateOfBirth, // format Y-m-d
    String? placeOfBirth,
  }) async {
    final res = await _client.dio.post('/auth/register', data: {
      'full_name': fullName,
      'phone': phone,
      'password': password,
      'password_confirmation': password,
      'profile': profile,
      if (businessName != null) 'business_name': businessName,
      if (businessCategory != null) 'business_category': businessCategory,
      if (address != null) 'address': address,
      if (pin != null) 'pin': pin,
      if (otp != null) 'otp': otp,
      if (dateOfBirth != null) 'date_of_birth': dateOfBirth,
      if (placeOfBirth != null) 'place_of_birth': placeOfBirth,
      ...await _deviceInfo(),
    });
    await _client.saveToken(res.data['token']);
    return FpUser.fromJson(res.data['user']);
  }

  Future<void> resetPin({required String phone, required String otp, String? password, String? idNumber, required String newPin}) async {
    await _client.dio.post('/auth/pin/reset', data: {
      'phone': phone, 'otp': otp,
      if (password != null && password.isNotEmpty) 'password': password,
      if (idNumber != null && idNumber.isNotEmpty) 'id_number': idNumber,
      'new_pin': newPin,
    });
  }

  Future<String> reportLost({required String phone, required String password}) async {
    final res = await _client.dio.post('/auth/report-lost', data: {'phone': phone, 'password': password});
    return res.data['message']?.toString() ?? 'Compte bloqué.';
  }

  Future<FpUser> me() async {
    final res = await _client.dio.get('/me');
    return FpUser.fromJson(res.data);
  }

  /// Mise à jour du profil (langue, email, date/lieu de naissance…), ex. depuis l'écran KYC.
  Future<FpUser> updateProfile({String? language, String? email, String? dateOfBirth, String? placeOfBirth}) async {
    final res = await _client.dio.patch('/me', data: {
      if (language != null) 'language': language,
      if (email != null) 'email': email,
      if (dateOfBirth != null) 'date_of_birth': dateOfBirth,
      if (placeOfBirth != null) 'place_of_birth': placeOfBirth,
    });
    return FpUser.fromJson(res.data);
  }

  Future<void> logout() async {
    try {
      await _client.dio.post('/auth/logout');
    } finally {
      await _client.clearToken();
    }
  }
}
