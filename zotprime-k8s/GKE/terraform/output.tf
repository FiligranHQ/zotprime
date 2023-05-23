
output "K8S_cluster_name" {
  description = "GKE Cluster name"
  value       = module.k8s.name
}

output "region" {
  value       = var.region
  description = "GCloud Region"
}

output "project_id" {
  value       = var.project_id
  description = "GCloud Project ID"
}

output "kubernetes_cluster_host" {
  value       = module.k8s.endpoint
  description = "GKE Cluster Host"
  sensitive   = true
}

#output "kubernetes_cluster_labels" {
#  value       = module.k8s.labels
#  description = "GKE Cluster labels"
#}

