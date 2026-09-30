class FpTransaction {
  final int id;
  final String reference;
  final String type;
  final String sourceRail;
  final String destinationRail;
  final int amount;
  final int fee;
  final String currency;
  final String status;
  final DateTime createdAt;

  FpTransaction({
    required this.id,
    required this.reference,
    required this.type,
    required this.sourceRail,
    required this.destinationRail,
    required this.amount,
    required this.fee,
    required this.currency,
    required this.status,
    required this.createdAt,
  });

  factory FpTransaction.fromJson(Map<String, dynamic> json) => FpTransaction(
        id: json['id'],
        reference: json['reference'] ?? '',
        type: json['type'] ?? '',
        sourceRail: json['source_rail'] ?? '',
        destinationRail: json['destination_rail'] ?? '',
        amount: (json['amount'] as num?)?.toInt() ?? 0,
        fee: (json['fee'] as num?)?.toInt() ?? 0,
        currency: json['currency'] ?? 'XAF',
        status: json['status'] ?? 'processing',
        createdAt: DateTime.tryParse(json['created_at'] ?? '') ?? DateTime.now(),
      );
}
