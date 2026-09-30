import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/user.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';
import '../widgets/fp_avatar.dart';
import '../services/linked_sources.dart';

enum SessionStatus { unknown, authenticated, unauthenticated }

/// État global de la session : profil actif (client ou marchand — deux
/// comptes séparés au sens du cahier des charges), utilisateur courant,
/// solde du wallet. Toutes les vues écoutent ce provider.
class SessionProvider extends ChangeNotifier {
  final _authService = AuthService();
  final _apiClient = ApiClient();

  SessionStatus status = SessionStatus.unknown;
  FpUser? user;
  FpProfile? activeProfile;

  SessionProvider() {
    _apiClient.onUnauthorized = () {
      user = null;
      status = SessionStatus.unauthenticated;
      notifyListeners();
    };
  }

  /// Habilitation accordée au profil connecté (cf. FpUser.can).
  bool can(String? cap) => user?.can(cap) ?? true;

  bool get isMerchantSession => activeProfile == FpProfile.merchant;
  bool get isClientSession => activeProfile == FpProfile.client;
  bool get isAgentSession => activeProfile == FpProfile.agent;
  bool get isCashierSession => activeProfile == FpProfile.cashier;

  static const _profileKey = 'fp_active_profile';

  /// Profil mémorisé au dernier login (un même numéro peut être client, marchand et agent).
  Future<void> _saveProfile(FpProfile? p) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      if (p == null) {
        await prefs.remove(_profileKey);
      } else {
        await prefs.setString(_profileKey, p.name);
      }
    } catch (_) {}
  }

  Future<FpProfile> _restoreProfile(FpUser u) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final saved = FpProfile.values.where((p) => p.name == prefs.getString(_profileKey)).firstOrNull;
      final allowed = switch (saved) {
        FpProfile.agent => u.isAgent,
        FpProfile.merchant => u.isMerchant,
        FpProfile.client => u.isClient,
        FpProfile.cashier => u.isCashier,
        null => false,
      };
      if (saved != null && allowed) return saved;
    } catch (_) {}
    if (u.isCashier) return FpProfile.cashier;
    if (u.isAgent && !u.isClient && !u.isMerchant) return FpProfile.agent;
    if (u.isMerchant && !u.isClient) return FpProfile.merchant;
    return FpProfile.client;
  }

  Future<void> bootstrap() async {
    final token = await _apiClient.readToken();
    if (token == null) {
      status = SessionStatus.unauthenticated;
      notifyListeners();
      return;
    }
    try {
      user = await _authService.me();
      activeProfile = await _restoreProfile(user!);
      status = SessionStatus.authenticated;
    } catch (_) {
      status = SessionStatus.unauthenticated;
    }
    notifyListeners();
  }

  Future<void> login({required String phone, required String password, required FpProfile profile, String? otp}) async {
    user = await _authService.login(phone: phone, password: password, profile: profile.name, otp: otp);
    activeProfile = profile;
    await _saveProfile(profile);
    status = SessionStatus.authenticated;
    notifyListeners();
  }

  Future<void> register({
    required String fullName,
    required String phone,
    required String password,
    required FpProfile profile,
    String? businessName,
    String? businessCategory,
    String? address,
    String? pin,
    String? otp,
    String? dateOfBirth,
    String? placeOfBirth,
  }) async {
    final profileStr = profile == FpProfile.merchant ? 'merchant' : 'client';
    user = await _authService.register(
      fullName: fullName,
      phone: phone,
      password: password,
      profile: profileStr,
      businessName: businessName,
      businessCategory: businessCategory,
      address: address,
      pin: pin,
      otp: otp,
      dateOfBirth: dateOfBirth,
      placeOfBirth: placeOfBirth,
    );
    await _saveProfile(profile);
    activeProfile = profile;
    status = SessionStatus.authenticated;
    notifyListeners();
  }

  /// Met à jour le profil (ex. date/lieu de naissance depuis l'écran KYC).
  Future<void> updateProfile({String? language, String? email, String? dateOfBirth, String? placeOfBirth}) async {
    user = await _authService.updateProfile(language: language, email: email, dateOfBirth: dateOfBirth, placeOfBirth: placeOfBirth);
    notifyListeners();
  }

  Future<void> refreshUser() async {
    user = await _authService.me();
    notifyListeners();
  }

  Future<void> logout() async {
    FpPrivateImages.clear();
    LinkedSources.clear();
    await _authService.logout();
    user = null;
    activeProfile = null;
    await _saveProfile(null);
    status = SessionStatus.unauthenticated;
    notifyListeners();
  }
}
