class FpWallet {
  final int id;
  final int balance;
  final String currency;
  final String? country; // CG, CM, SN… (pays du wallet)

  FpWallet({required this.id, required this.balance, required this.currency, this.country});

  factory FpWallet.fromJson(Map<String, dynamic> json) => FpWallet(
        id: json['id'],
        balance: (json['balance'] as num).toInt(),
        currency: json['currency'] ?? 'XAF',
        country: json['country'] as String?,
      );
}

class FpMerchant {
  final int id;
  final String businessName;
  final String qrCodeToken;
  final String validationStatus;

  FpMerchant({
    required this.id,
    required this.businessName,
    required this.qrCodeToken,
    required this.validationStatus,
  });

  factory FpMerchant.fromJson(Map<String, dynamic> json) => FpMerchant(
        id: json['id'],
        businessName: json['business_name'] ?? '',
        qrCodeToken: json['qr_code_token'] ?? '',
        validationStatus: json['validation_status'] ?? 'pending',
      );
}

/// Profil actif dans l'app : deux comptes séparés (client / marchand)
/// peuvent exister pour le même utilisateur physique, mais chaque
/// connexion ouvre UN espace précis (cf. AuthController::login côté API).
enum FpProfile { client, merchant, agent, cashier }

class FpUser {
  final int id;
  final String fullName;
  final String phone;
  final String? email;
  final List<String> roles;
  final String kycStatus;
  final FpWallet? wallet;
  final FpMerchant? merchant;
  final int kycTier; // 0 : téléphone, 1 : pièce, 2 : KYC complet (§12)
  final bool hasPin;
  final String? dateOfBirth; // format Y-m-d
  final String? placeOfBirth;
  final int unreadNotifications;
  final Map<String, dynamic>? limits;
  final Map<String, dynamic>? cashier; // sous-compte caissier (§3.2.3)
  final Map<String, dynamic>? agent;
  /// Habilitations effectives (console « Rôles & habilitations »).
  /// null = ancienne API : tout est affiché, l'API reste seule juge.
  final List<String>? permissions;

  FpUser({
    required this.id,
    required this.fullName,
    required this.phone,
    this.email,
    required this.roles,
    required this.kycStatus,
    this.wallet,
    this.merchant,
    this.kycTier = 0,
    this.hasPin = false,
    this.dateOfBirth,
    this.placeOfBirth,
    this.unreadNotifications = 0,
    this.limits,
    this.cashier,
    this.agent,
    this.permissions,
  });

  /// L'action [cap] est-elle autorisée pour ce profil ? (null = toujours)
  bool can(String? cap) => cap == null || permissions == null || permissions!.contains(cap);

  bool get isMerchant => roles.contains('merchant');
  bool get isClient => roles.contains('client');
  bool get isAgent => roles.contains('agent');
  bool get isCashier => roles.contains('cashier');
  bool get isSuperAgent => agent?['is_super_agent'] == true;

  factory FpUser.fromJson(Map<String, dynamic> json) => FpUser(
        id: json['id'],
        fullName: json['full_name'] ?? '',
        phone: json['phone'] ?? '',
        email: json['email'],
        roles: (json['roles'] as List?)?.map((e) => e.toString()).toList() ?? [],
        kycStatus: json['kyc_status'] ?? 'pending',
        wallet: json['wallet'] != null ? FpWallet.fromJson(json['wallet']) : null,
        merchant: json['merchant'] != null ? FpMerchant.fromJson(json['merchant']) : null,
        kycTier: (json['kyc_tier'] as num?)?.toInt() ?? 0,
        hasPin: json['has_pin'] == true,
        dateOfBirth: json['date_of_birth'] as String?,
        placeOfBirth: json['place_of_birth'] as String?,
        unreadNotifications: (json['unread_notifications'] as num?)?.toInt() ?? 0,
        limits: json['limits'] is Map ? Map<String, dynamic>.from(json['limits']) : null,
        cashier: json['cashier'] is Map ? Map<String, dynamic>.from(json['cashier']) : null,
        agent: json['agent'] is Map ? Map<String, dynamic>.from(json['agent']) : null,
        permissions: json['permissions'] is List ? (json['permissions'] as List).map((e) => e.toString()).toList() : null,
      );
}
