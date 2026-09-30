/// Moyens de recharge / retrait / paiement proposés pour un pays
/// (GET /api/pay/methods?country=CG&operation=deposit|withdraw|pay).
class FpMethod {
  final String key; // mobile_money | agent_qr | cash_pickup | atm | scan_qr | pay_code
  final String label;
  final String description;
  final String icon;
  final bool available;
  final String? reason;
  final List<String> operators;

  const FpMethod({
    required this.key,
    required this.label,
    required this.description,
    required this.icon,
    required this.available,
    this.reason,
    this.operators = const [],
  });

  factory FpMethod.fromJson(Map<String, dynamic> j) => FpMethod(
        key: j['key'] as String,
        label: j['label'] as String,
        description: (j['description'] ?? '') as String,
        icon: (j['icon'] ?? '') as String,
        available: j['available'] == true,
        reason: j['reason'] as String?,
        operators: ((j['operators'] ?? []) as List).map((o) => (o as Map)['label'].toString()).toSet().toList(),
      );
}

class FpMethods {
  final String country;
  final String name;
  final String flag;
  final String currency;
  final String operation;
  final List<FpMethod> methods;

  const FpMethods({
    required this.country,
    required this.name,
    required this.flag,
    required this.currency,
    required this.operation,
    required this.methods,
  });

  factory FpMethods.fromJson(Map<String, dynamic> j) => FpMethods(
        country: j['country'] as String,
        name: j['name'] as String,
        flag: (j['flag'] ?? '') as String,
        currency: j['currency'] as String,
        operation: j['operation'] as String,
        methods: (j['methods'] as List).map((e) => FpMethod.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
      );
}

/// Code de paiement client (style Alipay) : QR + 18 chiffres, usage unique.
class FpPayCode {
  final String code;
  final String qr;
  final DateTime expiresAt;

  const FpPayCode({required this.code, required this.qr, required this.expiresAt});

  factory FpPayCode.fromJson(Map<String, dynamic> j) => FpPayCode(
        code: j['code'] as String,
        qr: j['qr'] as String,
        expiresAt: DateTime.parse(j['expires_at'] as String).toLocal(),
      );

  /// 88 1234 5678 9012 3456
  String get grouped => code.replaceAllMapped(RegExp(r'^(\d{2})|(\d{4})'), (m) => '${m[0]} ').trim();
}

/// Bon de retrait : cash pickup chez un agent ou GAB partenaire.
class FpVoucher {
  final int id;
  final String channel; // cash_pickup | atm
  final String country;
  final int amount;
  final int fee;
  final String currency;
  final String status; // pending | redeemed | cancelled | expired
  final String? beneficiaryName;
  final DateTime expiresAt;
  final String? code;
  final String? qr;

  const FpVoucher({
    required this.id,
    required this.channel,
    required this.country,
    required this.amount,
    required this.fee,
    required this.currency,
    required this.status,
    this.beneficiaryName,
    required this.expiresAt,
    this.code,
    this.qr,
  });

  factory FpVoucher.fromJson(Map<String, dynamic> j) => FpVoucher(
        id: (j['id'] as num).toInt(),
        channel: j['channel'] as String,
        country: j['country'] as String,
        amount: (j['amount'] as num).toInt(),
        fee: ((j['fee'] ?? 0) as num).toInt(),
        currency: j['currency'] as String,
        status: j['status'] as String,
        beneficiaryName: j['beneficiary_name'] as String?,
        expiresAt: DateTime.parse(j['expires_at'] as String).toLocal(),
        code: j['code'] as String?,
        qr: j['qr'] as String?,
      );

  bool get isAtm => channel == 'atm';

  /// 1234 567 890
  String get groupedCode {
    final c = code ?? '';
    final b = StringBuffer();
    for (var i = 0; i < c.length; i++) {
      if (i > 0 && (c.length - i) % 3 == 0) b.write(' ');
      b.write(c[i]);
    }
    return b.toString();
  }
}
