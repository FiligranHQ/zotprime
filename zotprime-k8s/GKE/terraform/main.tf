provider "google" {
  credentials = file("auth/cred.json")
}

provider "google-beta" {
  credentials = file("auth/cred.json")
}
terraform {
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = ">= 4.66.0"
    }

    google-beta = {
      source  = "hashicorp/google-beta"
      version = ">= 4.66.0"
    }

    /*  kubectl = {
      source  = "gavinbunney/kubectl"
      version = ">= 1.14.0"
    } */
  }

  required_version = ">= 1.4.6"
}

module "gke_auth" {
  source       = "terraform-google-modules/kubernetes-engine/google//modules/auth"
  version      = "26.1.1"
  depends_on   = [module.k8s]
  project_id   = var.project_id
  location     = module.k8s.location
  cluster_name = module.k8s.name
}

module "vpc" {
  source       = "terraform-google-modules/network/google"
  project_id   = var.project_id
  network_name = "${var.network}-${var.env_name}"
  version      = "~> 7.0.0"
  #  version      = "6.0.0"
  subnets = [
    {
      subnet_name   = "${var.subnetwork}-${var.env_name}"
      subnet_ip     = "10.10.0.0/16"
      subnet_region = var.region
    },
  ]
  secondary_ranges = {
    "${var.subnetwork}-${var.env_name}" = [
      {
        range_name    = var.ip_range_pods_name
        ip_cidr_range = "10.20.0.0/16"
      },
      {
        range_name    = var.ip_range_services_name
        ip_cidr_range = "10.30.0.0/16"
      },
    ]
  }
}

module "k8s" {
  source                   = "terraform-google-modules/kubernetes-engine/google//modules/private-cluster"
  version                  = "26.1.1"
  project_id               = var.project_id
  name                     = "${var.cluster_name}-${var.env_name}"
  regional                 = false
  region                   = var.region
  zones                    = var.zones
  network                  = module.vpc.network_name
  subnetwork               = module.vpc.subnets_names[0]
  ip_range_pods            = var.ip_range_pods_name
  ip_range_services        = var.ip_range_services_name
  remove_default_node_pool = true
  initial_node_count       = 1
  gce_pd_csi_driver        = true

  create_service_account = false

  #  network_policy             = false
  #  horizontal_pod_autoscaling = true
  #  http_load_balancing        = true

  node_pools = [
    {
      name         = "nodepool"
      machine_type = var.machine
      #node_locations = "asia-southeast1-a,asia-southeast1-b,asia-southeast1-c"
      node_locations = var.node-locations # node_locations Optional. The list of zones in which the cluster's nodes are located. Nodes must be in the region of their regional cluster or in the same region as their cluster's zone for zonal clusters. Defaults to cluster level node locations if nothing is specified.
      min_count      = var.minnode
      max_count      = var.maxnode
      disk_size_gb   = var.disksize
      preemptible    = false
      auto_repair    = false
      auto_upgrade   = true
    },
  ]
  cluster_resource_labels    = { "env" = "${var.env_name}" }
  node_pools_labels          = { nodepool = { "env" = "${var.env_name}" } }
  node_pools_resource_labels = { nodepool = { "env" = "${var.env_name}" } }
  node_pools_tags            = { nodepool = ["zotprime"] }
}


resource "local_file" "kubeconfig" {
  content  = module.gke_auth.kubeconfig_raw
  filename = "kubeconfig-${var.env_name}"
}


#provider "kubectl" {
#  load_config_file = false
#  host             = "https://module.k8s.endpoint"
#  token            = module.gke_auth.token
#  #cluster_ca_certificate = base64decode(module.gke_auth.cluster_ca_certificate)
#  cluster_ca_certificate = base64decode(module.k8s.ca_certificate)
#}

#data "kubectl_filename_list" "manifests" {
#  pattern = "../manifests/*.yaml"
#}

#resource "kubectl_manifest" "zotprime" {
#  count     = length(data.kubectl_filename_list.manifests.matches)
#  yaml_body = file(element(data.kubectl_filename_list.manifests.matches, count.index))
#}

